<?php

namespace Tests\Feature;

use App\Models\CustomTableColumnValue;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Invalid email cells (two addresses jammed as "a@x - b@y", header labels)
 * import verbatim instead of failing whole rows — the file wins (2026-09-30
 * decision; 3 rows died on TSP Email validation).
 */
class ImportEmailTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_email_cells_land_verbatim(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $jammed = 'joven.comjoven.padon@mcbtsi.com - joven.padon@mcbtsi.com';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Service Requests');
        $sheet->setCellValue('A1', 'Service Request No');
        $sheet->setCellValue('B1', 'Contact');
        $sheet->setCellValue('A2', 'SR-6001');
        $sheet->setCellValue('B2', $jammed);

        $path = tempnam(sys_get_temp_dir(), 'email-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $uploadId = str_repeat('9c', 16);
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
            [
                'uploadId' => $uploadId,
                'originalName' => 'workbook.xlsx',
                'sheet' => 'Service Requests',
                'headerRow' => 1,
                'dataStart' => 2,
                'mapping' => ['service_request_no' => 'A'],
                'newColumns' => [['letter' => 'B', 'name' => 'Contact', 'type' => 'email']],
                'columnSignature' => ['A' => 'Service Request No', 'B' => 'Contact'],
            ]
        )->assertOk()->json();

        $this->postJson(
            '/tables/service-requests/import-classic/chunk',
            ['batchId' => $prepare['batchId'], 'offset' => 0, 'limit' => 250]
        )->assertOk();

        $finish = $this->postJson(
            '/tables/service-requests/import-classic/finish',
            ['batchId' => $prepare['batchId']]
        )->assertOk()->json();

        $this->assertSame(0, $finish['failed'], json_encode($finish));
        ServiceRequest::query()->where('service_request_number', 'SR-6001')->firstOrFail();

        $value = CustomTableColumnValue::query()->firstOrFail();
        $this->assertSame($jammed, $value->value['email'] ?? null);
        $this->assertSame($jammed, $value->value_text);
    }
}
