<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Chunked core-table imports: prepare validates and stages the structure,
 * small chunk calls write row ranges (each its own short request, so the
 * host cannot kill a 500+ row import halfway), and finish completes the
 * batch. Quick import builds the mapping from the file itself — headers
 * auto-match, leftovers become new columns — with no manual connecting.
 */
class CoreImportChunkedTest extends TestCase
{
    use RefreshDatabase;

    /** Four-row Service Requests workbook with exact-match headers + one extra column. */
    private function streamFourRowWorkbook(string $uploadId): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Service Requests');
        $sheet->setCellValue('A1', 'Service Request No');
        $sheet->setCellValue('B1', 'Customer Name');
        $sheet->setCellValue('C1', 'Note');

        for ($row = 2; $row <= 5; $row++) {
            $sheet->setCellValue('A'.$row, 'SR-700'.$row);
            $sheet->setCellValue('B'.$row, 'Hospital '.$row);
            $sheet->setCellValue('C'.$row, 'note '.$row);
        }

        $path = tempnam(sys_get_temp_dir(), 'chunked-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->call('POST', '/import/upload-chunk', [
            'uploadId' => $uploadId,
            'offset' => '0',
            'fileName' => 'workbook.xlsx',
        ], [], [
            'chunk' => new UploadedFile($path, 'workbook.xlsx', 'application/octet-stream', null, true),
        ])->assertOk();

        @unlink($path);
    }

    private function preparePayload(string $uploadId, array $extra = []): array
    {
        return array_merge([
            'uploadId' => $uploadId,
            'originalName' => 'workbook.xlsx',
            'sheet' => 'Service Requests',
            'headerRow' => 1,
            'dataStart' => 2,
            'mapping' => ['service_request_no' => 'A', 'customer_name' => 'B'],
            'newColumns' => [['letter' => 'C', 'name' => 'Note', 'type' => 'text']],
            'columnSignature' => ['A' => 'Service Request No', 'B' => 'Customer Name', 'C' => 'Note'],
        ], $extra);
    }

    public function test_prepare_chunk_chunk_finish_writes_every_row(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $uploadId = str_repeat('dd', 16);
        $this->streamFourRowWorkbook($uploadId);

        $prepare = $this->postJson(
            '/tables/service-requests/import-classic/prepare',
            $this->preparePayload($uploadId)
        )->assertOk()->json();

        $this->assertSame('processing', $prepare['status'], json_encode($prepare));
        $this->assertSame(4, $prepare['totalRows'], json_encode($prepare));
        $batchId = $prepare['batchId'];

        // Structure is staged at prepare time, before any row is written.
        $this->assertDatabaseHas('table_custom_columns', [
            'table_key' => 'service-requests',
            'name' => 'Note',
        ]);

        $first = $this->postJson(
            '/tables/service-requests/import-classic/chunk',
            ['batchId' => $batchId, 'offset' => 0, 'limit' => 2]
        )->assertOk()->json();

        $this->assertFalse($first['done'], json_encode($first));

        $second = $this->postJson(
            '/tables/service-requests/import-classic/chunk',
            ['batchId' => $batchId, 'offset' => $first['nextOffset'], 'limit' => 2]
        )->assertOk()->json();

        $this->assertTrue($second['done'], json_encode($second));
        $this->assertSame(4, $second['processed'], json_encode($second));

        $finish = $this->postJson(
            '/tables/service-requests/import-classic/finish',
            ['batchId' => $batchId]
        )->assertOk()->json();

        $this->assertSame('completed', $finish['status'], json_encode($finish));
        $this->assertSame(4, $finish['processed'], json_encode($finish));
        $this->assertSame(0, $finish['failed'], json_encode($finish));

        $this->assertSame(4, ServiceRequest::query()->count());
        $this->assertSame(
            'Hospital 3',
            ServiceRequest::query()->where('service_request_number', 'SR-7003')->firstOrFail()->customer_name
        );
    }

    public function test_quick_import_builds_mapping_from_the_file(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $uploadId = str_repeat('ee', 16);
        $this->streamFourRowWorkbook($uploadId);

        $prepare = $this->postJson(
            '/tables/service-requests/import-classic/prepare',
            $this->preparePayload($uploadId, [
                'autoMap' => true,
                'mapping' => [],
                'newColumns' => [],
            ])
        )->assertOk()->json();

        $this->assertSame(4, $prepare['totalRows'], json_encode($prepare));
        $batchId = $prepare['batchId'];

        // Exact-match headers connected themselves; the leftover became a column.
        $this->assertDatabaseHas('table_custom_columns', [
            'table_key' => 'service-requests',
            'name' => 'Note',
        ]);

        $this->postJson(
            '/tables/service-requests/import-classic/chunk',
            ['batchId' => $batchId, 'offset' => 0, 'limit' => 250]
        )->assertOk();

        $finish = $this->postJson(
            '/tables/service-requests/import-classic/finish',
            ['batchId' => $batchId]
        )->assertOk()->json();

        $this->assertSame('completed', $finish['status'], json_encode($finish));
        $this->assertSame(4, $finish['processed'], json_encode($finish));
        $this->assertSame(
            'Hospital 4',
            ServiceRequest::query()->where('service_request_number', 'SR-7004')->firstOrFail()->customer_name
        );
    }

    public function test_cancel_marks_the_batch_failed_so_undo_stays_available(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $uploadId = str_repeat('ff', 16);
        $this->streamFourRowWorkbook($uploadId);

        $prepare = $this->postJson(
            '/tables/service-requests/import-classic/prepare',
            $this->preparePayload($uploadId)
        )->assertOk()->json();

        $this->postJson(
            '/tables/service-requests/import-classic/cancel',
            ['batchId' => $prepare['batchId']]
        )->assertOk();

        $this->assertSame(
            'failed',
            ImportBatch::query()->findOrFail($prepare['batchId'])->status
        );
    }

    public function test_prepare_rejects_an_empty_mapping_without_automap(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $uploadId = str_repeat('a1', 16);
        $this->streamFourRowWorkbook($uploadId);

        $this->postJson(
            '/tables/service-requests/import-classic/prepare',
            $this->preparePayload($uploadId, ['mapping' => [], 'newColumns' => []])
        )->assertStatus(422);
    }
}
