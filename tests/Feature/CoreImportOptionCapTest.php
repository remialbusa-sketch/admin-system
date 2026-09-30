<?php

namespace Tests\Feature;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\ImportFailure;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The option cap (ImportOptionSeeder::MAX_OPTIONS) is a runaway-file guard,
 * not a row-killer: hitting it while seeding one dropdown cell must not
 * discard that row's other columns. On the live host one column sitting at
 * the old 200-option cap failed 2,809 of 4,055 rows on a single import —
 * every failed row lost ALL of its data, not just the over-cap cell.
 */
class CoreImportOptionCapTest extends TestCase
{
    use RefreshDatabase;

    /** Technical Reports workbook: Reference Number + Customer In-Charge. */
    private function streamWorkbook(string $uploadId): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Technical Reports');
        $sheet->setCellValue('A1', 'Reference Number');
        $sheet->setCellValue('B1', 'Customer In-Charge');
        $sheet->setCellValue('A2', 'REF-900');
        $sheet->setCellValue('B2', 'Customer Name 999');

        $path = tempnam(sys_get_temp_dir(), 'optcap-').'.xlsx';
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

    /**
     * A connected dropdown column that already sits at the old 200-option
     * cap receives a file label it has never seen: the option must seed
     * past the old limit and the row must land with all of its columns.
     */
    public function test_row_lands_with_a_new_dropdown_label_when_the_option_list_is_full(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        // The column filled its option list on earlier runs (as on the host).
        $options = collect(range(1, 200))->map(fn (int $i): array => [
            'index' => $i - 1,
            'label' => 'Name '.$i,
            'color' => '#64748B',
        ])->all();

        $column = CustomTableColumn::create([
            'table_key' => 'technical-reports',
            'name' => 'Customer In-Charge',
            'type' => 'dropdown',
            'settings' => ['options' => $options],
            'position' => 1,
        ]);

        $uploadId = str_repeat('ca', 16);
        $this->streamWorkbook($uploadId);

        $prepare = $this->postJson('/tables/technical-reports/import-classic/prepare', [
            'uploadId' => $uploadId,
            'originalName' => 'workbook.xlsx',
            'sheet' => 'Technical Reports',
            'headerRow' => 1,
            'dataStart' => 2,
            'mapping' => ['reference_number' => 'A', 'custom_'.$column->id => 'B'],
            'newColumns' => [],
            'columnTypes' => ['B' => ['type' => 'dropdown']],
            'columnSignature' => ['A' => 'Reference Number', 'B' => 'Customer In-Charge'],
        ])->assertOk()->json();

        $this->postJson('/tables/technical-reports/import-classic/chunk', [
            'batchId' => $prepare['batchId'], 'offset' => 0, 'limit' => 250,
        ])->assertOk();

        $finish = $this->postJson('/tables/technical-reports/import-classic/finish', [
            'batchId' => $prepare['batchId'],
        ])->assertOk()->json();

        $failures = ImportFailure::query()->get()->map(fn (ImportFailure $f): string => $f->error_message)->all();
        $this->assertSame(0, $finish['failed'], 'Row failed: '.json_encode($failures));

        $report = TechnicalReport::query()->where('reference_number', 'REF-900')->first();
        $this->assertNotNull($report, 'Row missing entirely: '.json_encode($failures));

        $value = CustomTableColumnValue::query()
            ->where('custom_column_id', $column->id)
            ->where('row_id', $report->id)
            ->firstOrFail();
        $this->assertSame('Customer Name 999', $value->value_text);

        $column->refresh();
        $labels = array_column($column->settings['options'] ?? [], 'label');
        $this->assertCount(201, $labels, 'The 201st option must seed instead of failing the row.');
        $this->assertSame('Customer Name 999', end($labels));
    }
}
