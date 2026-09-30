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
 * value_text is a VARCHAR(255) search shadow; the full cell value lives in
 * `value`. A longer shadow makes MySQL reject the row with SQLSTATE 22001
 * ("Data too long for column 'value_text'"), killing whole rows on import —
 * 8 rows died this way in the 2026-09-30 import (Serial Number / Remarks
 * cells over 255 chars). The shadow is capped at the shared write boundary;
 * the stored value keeps the full text (the file wins).
 */
class ImportValueTextCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_cells_land_with_full_value_and_a_255_char_shadow(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $long = str_repeat('Serial list with commas, ', 22); // 572 chars

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Service Requests');
        $sheet->setCellValue('A1', 'Service Request No');
        $sheet->setCellValue('B1', 'Contact Note');
        $sheet->setCellValue('A2', 'SR-9001');
        $sheet->setCellValue('B2', $long);

        $path = tempnam(sys_get_temp_dir(), 'cap-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $uploadId = str_repeat('5a', 16);
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
                'newColumns' => [['letter' => 'B', 'name' => 'Contact Note', 'type' => 'text']],
                'columnSignature' => ['A' => 'Service Request No', 'B' => 'Contact Note'],
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
        ServiceRequest::query()->where('service_request_number', 'SR-9001')->firstOrFail();

        $value = CustomTableColumnValue::query()->firstOrFail();

        // The file wins: the full cell text stays stored (text values are
        // trimmed by validate; nothing else may be dropped).
        $this->assertSame(trim($long), $value->value['text'] ?? null);

        // The search shadow never exceeds the VARCHAR(255) it is written to.
        $this->assertSame(255, mb_strlen($value->value_text ?? ''), 'Search shadow must be capped at 255 chars.');
    }
}
