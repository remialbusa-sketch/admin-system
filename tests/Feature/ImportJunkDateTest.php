<?php

namespace Tests\Feature;

use App\Models\CustomTableColumnValue;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * A present-but-garbage date cell must import as no date instead of
 * producing 1969 junk (a bare negative numeric read as a Unix timestamp)
 * or failing the row when a TIMESTAMP column rejects it. The original cell
 * stays in raw_data either way — the 2026-09-30 file carried three cells
 * like -691914.37380787 that killed their rows.
 */
class ImportJunkDateTest extends TestCase
{
    use RefreshDatabase;

    /** Upload a technical-reports workbook and run it through prepare/chunk/finish. */
    private function importWorkbook(array $headers, array $cells, array $mapping, array $newColumns): array
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Technical Reports');
        foreach ($headers as $letter => $label) {
            $sheet->setCellValue($letter.'1', $label);
        }
        foreach ($cells as $coordinate => $value) {
            $sheet->setCellValue($coordinate, $value);
        }

        $path = tempnam(sys_get_temp_dir(), 'junkdate-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $uploadId = str_repeat('7e', 16);
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
                'mapping' => $mapping,
                'newColumns' => $newColumns,
                'columnSignature' => collect($headers)->mapWithKeys(
                    fn ($label, $letter) => [$letter => $label]
                )->all(),
            ]
        )->assertOk()->json();

        $this->postJson(
            '/tables/technical-reports/import-classic/chunk',
            ['batchId' => $prepare['batchId'], 'offset' => 0, 'limit' => 250]
        )->assertOk();

        return $this->postJson(
            '/tables/technical-reports/import-classic/finish',
            ['batchId' => $prepare['batchId']]
        )->assertOk()->json();
    }

    public function test_junk_fixed_datetime_cell_lands_the_row_with_a_null_date(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $finish = $this->importWorkbook(
            ['A' => 'Reference Number', 'B' => 'Service End Date & Time'],
            ['A2' => 'REF-1', 'B2' => '-691914.37380787'],
            ['reference_number' => 'A', 'service_end_date_time' => 'B'],
            []
        );

        $this->assertSame(0, $finish['failed'], json_encode($finish));

        $report = TechnicalReport::query()->where('reference_number', 'REF-1')->firstOrFail();
        $this->assertNull(
            $report->service_completed_at,
            'Garbage date cells import as no date, never as 1969 junk.'
        );
    }

    public function test_junk_custom_date_cell_lands_as_no_date(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $finish = $this->importWorkbook(
            ['A' => 'Reference Number', 'B' => 'Log-In Date'],
            ['A2' => 'REF-2', 'B2' => '-691914.37380787'],
            ['reference_number' => 'A'],
            [['letter' => 'B', 'name' => 'Log-In Date', 'type' => 'date']]
        );

        $this->assertSame(0, $finish['failed'], json_encode($finish));

        TechnicalReport::query()->where('reference_number', 'REF-2')->firstOrFail();
        $value = CustomTableColumnValue::query()->firstOrFail();
        $this->assertNull($value->value['date'] ?? null, 'Garbage date cells store no date.');
    }
}
