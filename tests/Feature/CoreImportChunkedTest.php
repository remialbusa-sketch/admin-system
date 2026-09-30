<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ServiceRequest;
use App\Models\TechnicalReport;
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

    /**
     * Abbreviated real-world headers ("TSP ASSIGNED", "SR No") connect to
     * their fixed fields through declared aliases instead of landing in
     * lookalike custom columns with the fixed fields left empty.
     */
    public function test_quick_import_connects_aliased_headers_to_fixed_fields(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Technical Reports');
        $sheet->setCellValue('A1', 'Reference Number');
        $sheet->setCellValue('B1', 'TSP ASSIGNED');
        $sheet->setCellValue('C1', 'SR No');
        $sheet->setCellValue('A2', 'REF-1');
        $sheet->setCellValue('B2', 'Roel Bagasbas');
        $sheet->setCellValue('C2', 'SR-11');
        $sheet->setCellValue('A3', 'REF-2');
        $sheet->setCellValue('B3', 'Jane Doe');
        $sheet->setCellValue('C3', 'SR-22');

        $path = tempnam(sys_get_temp_dir(), 'chunked-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $uploadId = str_repeat('c3', 16);
        $this->call('POST', '/import/upload-chunk', [
            'uploadId' => $uploadId,
            'offset' => '0',
            'fileName' => 'workbook.xlsx',
        ], [], [
            'chunk' => new UploadedFile($path, 'workbook.xlsx', 'application/octet-stream', null, true),
        ])->assertOk();

        @unlink($path);

        $prepare = $this->postJson(
            '/tables/technical-reports/import-classic/prepare',
            [
                'uploadId' => $uploadId,
                'originalName' => 'workbook.xlsx',
                'sheet' => 'Technical Reports',
                'headerRow' => 1,
                'dataStart' => 2,
                'autoMap' => true,
                'mapping' => [],
                'newColumns' => [],
                'columnSignature' => ['A' => 'Reference Number', 'B' => 'TSP ASSIGNED', 'C' => 'SR No'],
            ]
        )->assertOk()->json();

        $this->postJson(
            '/tables/technical-reports/import-classic/chunk',
            ['batchId' => $prepare['batchId'], 'offset' => 0, 'limit' => 250]
        )->assertOk();

        $this->postJson(
            '/tables/technical-reports/import-classic/finish',
            ['batchId' => $prepare['batchId']]
        )->assertOk();

        $report = TechnicalReport::query()->where('reference_number', 'REF-1')->firstOrFail();
        $this->assertSame('Roel Bagasbas', $report->tsp_name);
        $this->assertSame('SR-11', $report->service_request_number);
    }

    /**
     * total_rows comes from the sheet's used range, which counts fully-empty
     * rows; the writer skips them without counting, so processed+failed can
     * never reach total. `done` must come from range coverage instead —
     * otherwise one blank row strands the batch in `processing` forever and
     * finish never runs (2026-09-30 incident: 4,038+16 stuck below 4,055).
     */
    public function test_an_empty_row_inside_the_data_range_cannot_stall_the_batch(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Service Requests');
        $sheet->setCellValue('A1', 'Service Request No');
        $sheet->setCellValue('B1', 'Customer Name');
        $sheet->setCellValue('A2', 'SR-8001');
        $sheet->setCellValue('B2', 'Hospital 1');
        $sheet->setCellValue('A3', 'SR-8002');
        $sheet->setCellValue('B3', 'Hospital 2');
        // Row 4 stays fully empty inside the used range (row 5 has data).
        $sheet->setCellValue('A5', 'SR-8003');
        $sheet->setCellValue('B5', 'Hospital 3');

        $path = tempnam(sys_get_temp_dir(), 'chunked-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $uploadId = str_repeat('b7', 16);
        $this->call('POST', '/import/upload-chunk', [
            'uploadId' => $uploadId,
            'offset' => '0',
            'fileName' => 'workbook.xlsx',
        ], [], [
            'chunk' => new UploadedFile($path, 'workbook.xlsx', 'application/octet-stream', null, true),
        ])->assertOk();

        @unlink($path);

        $prepare = $this->postJson(
            '/tables/service-requests/import-classic/prepare',
            $this->preparePayload($uploadId)
        )->assertOk()->json();

        // The empty row counts toward the sheet's used range.
        $this->assertSame(4, $prepare['totalRows'], json_encode($prepare));

        // Two 2-row chunks: `done` must come from the UNION of completed
        // ranges covering every row, not from one range or from counters.
        $first = $this->postJson(
            '/tables/service-requests/import-classic/chunk',
            ['batchId' => $prepare['batchId'], 'offset' => 0, 'limit' => 2]
        )->assertOk()->json();

        $this->assertSame(2, $first['processed'], json_encode($first));
        $this->assertFalse($first['done'], 'Only half the row ranges are covered: '.json_encode($first));

        $second = $this->postJson(
            '/tables/service-requests/import-classic/chunk',
            ['batchId' => $prepare['batchId'], 'offset' => $first['nextOffset'], 'limit' => 2]
        )->assertOk()->json();

        $this->assertSame(3, $second['processed'], json_encode($second));
        $this->assertTrue(
            $second['done'],
            'The completed ranges cover every row, so the batch must report done even though counters (3) stay below total (4): '.json_encode($second)
        );

        $finish = $this->postJson(
            '/tables/service-requests/import-classic/finish',
            ['batchId' => $prepare['batchId']]
        )->assertOk()->json();

        $this->assertNotSame('processing', $finish['status'], json_encode($finish));
        $this->assertSame(3, ServiceRequest::query()->count());
    }
}
