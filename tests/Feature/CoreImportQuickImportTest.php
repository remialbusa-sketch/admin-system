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
 * Quick import must (a) capture a leftover header like "Address" as a real
 * column WITH its data, and (b) never retype an existing table column: a
 * dropdown the user configured stays a dropdown (quick import sends every
 * leftover as text, which used to convert the column and wipe its options).
 */
class CoreImportQuickImportTest extends TestCase
{
    use RefreshDatabase;

    /** Technical Reports workbook: Reference Number + Address + Type of Request. */
    private function streamWorkbook(string $uploadId): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Technical Reports');
        $sheet->setCellValue('A1', 'Reference Number');
        $sheet->setCellValue('B1', 'Address');
        $sheet->setCellValue('C1', 'Type of Request');
        $sheet->setCellValue('A2', 'REF-900');
        $sheet->setCellValue('B2', 'Lapu-Lapu City, Cebu, Philippines');
        $sheet->setCellValue('C2', 'In-House Repair');
        $sheet->setCellValue('A3', 'REF-901');
        $sheet->setCellValue('B3', 'Cebu City, Cebu, Philippines');
        $sheet->setCellValue('C3', 'On-Site Repair');

        $path = tempnam(sys_get_temp_dir(), 'quick-').'.xlsx';
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

    private function quickImport(string $uploadId): array
    {
        return $this->postJson('/tables/technical-reports/import-classic/prepare', [
            'uploadId' => $uploadId,
            'originalName' => 'workbook.xlsx',
            'sheet' => 'Technical Reports',
            'headerRow' => 1,
            'dataStart' => 2,
            'autoMap' => true,
            'mapping' => [],
            'newColumns' => [],
            'columnSignature' => ['A' => 'Reference Number', 'B' => 'Address', 'C' => 'Type of Request'],
        ])->assertOk()->json();
    }

    private function runChunks(array $prepare): array
    {
        $this->postJson('/tables/technical-reports/import-classic/chunk', [
            'batchId' => $prepare['batchId'], 'offset' => 0, 'limit' => 250,
        ])->assertOk();

        return $this->postJson('/tables/technical-reports/import-classic/finish', [
            'batchId' => $prepare['batchId'],
        ])->assertOk()->json();
    }

    public function test_quick_import_captures_the_address_column_with_its_data(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $uploadId = str_repeat('ad', 16);
        $this->streamWorkbook($uploadId);
        $this->runChunks($this->quickImport($uploadId));

        $column = CustomTableColumn::query()
            ->where('table_key', 'technical-reports')
            ->where('name', 'Address')
            ->firstOrFail();

        $report = TechnicalReport::query()->where('reference_number', 'REF-900')->firstOrFail();
        $value = CustomTableColumnValue::query()
            ->where('custom_column_id', $column->id)
            ->where('row_id', $report->id)
            ->firstOrFail();

        $this->assertSame('Lapu-Lapu City, Cebu, Philippines', $value->value_text);
    }

    public function test_quick_import_keeps_an_existing_dropdown_column_a_dropdown(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        // A dropdown the user configured in an earlier import — the app always
        // stores options as {index, label, color}.
        $column = CustomTableColumn::create([
            'table_key' => 'technical-reports',
            'name' => 'Type of Request',
            'type' => 'dropdown',
            'settings' => [
                'multi' => true,
                'options' => [
                    ['index' => 0, 'label' => 'In-House Repair', 'color' => '#2563EB'],
                    ['index' => 1, 'label' => 'On-Site Repair', 'color' => '#16A34A'],
                ],
            ],
            'position' => 1,
        ]);

        $uploadId = str_repeat('db', 16);
        $this->streamWorkbook($uploadId);
        $this->runChunks($this->quickImport($uploadId));

        $column->refresh();

        $this->assertSame('dropdown', $column->type, 'Quick import must not retype an existing dropdown column.');
        $this->assertSame(
            ['In-House Repair', 'On-Site Repair'],
            array_column($column->settings['options'] ?? [], 'label'),
            'Quick import must not wipe the configured options.'
        );

        $failures = ImportFailure::query()->get()->map(fn (ImportFailure $f): string => $f->error_message)->all();
        $report = TechnicalReport::query()->where('reference_number', 'REF-900')->first();
        $this->assertNotNull($report, 'row missing; failures: '.json_encode($failures));

        $value = CustomTableColumnValue::query()
            ->where('custom_column_id', $column->id)
            ->where('row_id', $report->id)
            ->firstOrFail();

        $this->assertSame('In-House Repair', $value->value_text);
    }

    /**
     * Manual step 3: a "+ New column…" draft typed as dropdown with its own
     * configured options must land as that dropdown with those options.
     */
    public function test_manual_import_lands_a_new_dropdown_column_with_its_configured_options(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $uploadId = str_repeat('dc', 16);
        $this->streamWorkbook($uploadId);

        $prepare = $this->postJson('/tables/technical-reports/import-classic/prepare', [
            'uploadId' => $uploadId,
            'originalName' => 'workbook.xlsx',
            'sheet' => 'Technical Reports',
            'headerRow' => 1,
            'dataStart' => 2,
            'mapping' => ['reference_number' => 'A'],
            'newColumns' => [
                ['letter' => 'C', 'name' => 'Type of Request', 'type' => 'dropdown', 'options' => [
                    ['label' => 'In-House Repair', 'color' => '#2563EB'],
                    ['label' => 'On-Site Repair', 'color' => '#16A34A'],
                ]],
            ],
            'columnTypes' => [],
            'columnSignature' => ['A' => 'Reference Number', 'B' => 'Address', 'C' => 'Type of Request'],
        ])->assertOk()->json();

        $this->runChunks($prepare);

        $column = CustomTableColumn::query()
            ->where('table_key', 'technical-reports')
            ->where('name', 'Type of Request')
            ->firstOrFail();

        $this->assertSame('dropdown', $column->type);
        $this->assertSame(
            ['In-House Repair', 'On-Site Repair'],
            array_column($column->settings['options'] ?? [], 'label')
        );

        $report = TechnicalReport::query()->where('reference_number', 'REF-900')->firstOrFail();
        $value = CustomTableColumnValue::query()
            ->where('custom_column_id', $column->id)
            ->where('row_id', $report->id)
            ->firstOrFail();

        $this->assertSame('In-House Repair', $value->value_text);
    }

    /**
     * Manual step 3: the type pick on a letter connected to an EXISTING custom
     * column must apply the picked type AND the options configured in the
     * step-3 option editor — the pick used to convert with default options,
     * silently replacing what the user configured.
     */
    public function test_manual_import_applies_dropdown_pick_and_options_to_a_connected_custom_column(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $column = CustomTableColumn::create([
            'table_key' => 'technical-reports',
            'name' => 'Type of Request',
            'type' => 'text',
            'settings' => [],
            'position' => 1,
        ]);

        $uploadId = str_repeat('dd', 16);
        $this->streamWorkbook($uploadId);

        $prepare = $this->postJson('/tables/technical-reports/import-classic/prepare', [
            'uploadId' => $uploadId,
            'originalName' => 'workbook.xlsx',
            'sheet' => 'Technical Reports',
            'headerRow' => 1,
            'dataStart' => 2,
            'mapping' => ['reference_number' => 'A', 'custom_'.$column->id => 'C'],
            'newColumns' => [],
            'columnTypes' => [
                'C' => ['type' => 'dropdown', 'options' => [
                    ['label' => 'In-House Repair', 'color' => '#2563EB'],
                    ['label' => 'On-Site Repair', 'color' => '#16A34A'],
                ]],
            ],
            'columnSignature' => ['A' => 'Reference Number', 'B' => 'Address', 'C' => 'Type of Request'],
        ])->assertOk()->json();

        $this->runChunks($prepare);

        $column->refresh();

        $this->assertSame('dropdown', $column->type, 'The step-3 dropdown pick on a connected column must apply.');
        $this->assertSame(
            ['In-House Repair', 'On-Site Repair'],
            array_column($column->settings['options'] ?? [], 'label'),
            'The options configured in step 3 must apply, not the default starters.'
        );

        $report = TechnicalReport::query()->where('reference_number', 'REF-900')->firstOrFail();
        $value = CustomTableColumnValue::query()
            ->where('custom_column_id', $column->id)
            ->where('row_id', $report->id)
            ->firstOrFail();

        $this->assertSame('In-House Repair', $value->value_text);
    }
}
