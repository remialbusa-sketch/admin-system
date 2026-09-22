<?php

namespace Tests\Feature;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_streamed_chunks_assemble_and_analyze_a_workbook(): void
    {
        $user = User::factory()->superadmin()->create();
        $this->actingAs($user);

        // A real single-sheet workbook the wizard can analyze.
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Service Requests');
        $sheet->setCellValue('A1', 'Service Request No');
        $sheet->setCellValue('B1', 'Customer');
        $sheet->setCellValue('A2', 'SR-7001');
        $sheet->setCellValue('B2', 'Stream Hospital');
        $path = tempnam(sys_get_temp_dir(), 'stream-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $content = (string) file_get_contents($path);
        $uploadId = str_repeat('ab', 16); // 32 hex chars

        $this->call('PUT', '/import/upload-stream', [], [], [], [
            'HTTP_X_FILE_NAME' => 'workbook.xlsx',
            'HTTP_X_UPLOAD_ID' => $uploadId,
            'HTTP_X_FILE_OFFSET' => '0',
            'CONTENT_TYPE' => 'application/octet-stream',
        ], substr($content, 0, 400));

        $this->call('PUT', '/import/upload-stream', [], [], [], [
            'HTTP_X_FILE_NAME' => 'workbook.xlsx',
            'HTTP_X_UPLOAD_ID' => $uploadId,
            'HTTP_X_FILE_OFFSET' => '400',
            'CONTENT_TYPE' => 'application/octet-stream',
        ], substr($content, 400));

        $relative = 'imports/stream-'.$uploadId.'.xlsx';
        Storage::disk(config('filesystems.default'))->assertExists($relative);

        // The classic wizard analyzes via a plain JSON POST (no Livewire).
        $this->postJson('/tables/service-requests/import-classic/analyze', [
            'uploadId' => $uploadId,
            'originalName' => 'workbook.xlsx',
        ])
            ->assertOk()
            ->assertJsonPath('preview.sheet', 'Service Requests')
            ->assertJsonPath('preview.headerRow', 1)
            ->assertJsonPath('preview.totalRows', 1)
            // Auto-mapping suggests the identity column from its header label.
            ->assertJsonPath('suggested.service_request_no', 'A');

        Storage::disk(config('filesystems.default'))->delete($relative);
        @unlink($path);
    }

    public function test_streamed_upload_is_forbidden_for_non_superadmins(): void
    {
        $this->actingAs(User::factory()->president()->create());

        $this->call('PUT', '/import/upload-stream', [], [], [], [
            'HTTP_X_FILE_NAME' => 'workbook.xlsx',
            'HTTP_X_UPLOAD_ID' => str_repeat('cd', 16),
            'HTTP_X_FILE_OFFSET' => '0',
            'CONTENT_TYPE' => 'application/octet-stream',
        ], 'x')->assertForbidden();
    }

    public function test_streamed_upload_rejects_disallowed_extensions(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $this->call('PUT', '/import/upload-stream', [], [], [], [
            'HTTP_X_FILE_NAME' => 'invoice.pdf',
            'HTTP_X_UPLOAD_ID' => str_repeat('ef', 16),
            'HTTP_X_FILE_OFFSET' => '0',
            'CONTENT_TYPE' => 'application/octet-stream',
        ], '%PDF-1.4')->assertStatus(422);
    }

    public function test_out_of_order_chunks_are_rejected(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $this->call('PUT', '/import/upload-stream', [], [], [], [
            'HTTP_X_FILE_NAME' => 'workbook.xlsx',
            'HTTP_X_UPLOAD_ID' => str_repeat('fe', 16),
            'HTTP_X_FILE_OFFSET' => '100',
            'CONTENT_TYPE' => 'application/octet-stream',
        ], 'x')->assertStatus(409);
    }

    /**
     * The classic (non-Livewire) page must render through the dashboard shell.
     * Regression guard: a <x-layouts.dashboard> tag with no matching component
     * view breaks Volt's ensureViewsAreCached() (which compiles every view) and
     * takes down unrelated tests.
     */
    public function test_classic_import_page_renders_with_the_dashboard_shell(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $this->get('/tables/service-requests/import-classic')
            ->assertOk()
            ->assertSee('classicImporter', false);
    }

    /**
     * POST multipart chunk fallback (for hosts where ModSecurity blocks PUT)
     * assembles the workbook, then the plain-JSON analyze endpoint returns the
     * preview without touching /livewire/update.
     */
    public function test_classic_post_chunk_upload_assembles_and_analyzes(): void
    {
        $user = User::factory()->superadmin()->create();
        $this->actingAs($user);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Service Requests');
        $sheet->setCellValue('A1', 'SR No');
        $sheet->setCellValue('B1', 'Customer');
        $sheet->setCellValue('A2', 'SR-7002');
        $sheet->setCellValue('B2', 'Classic Hospital');
        $path = tempnam(sys_get_temp_dir(), 'classic-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $uploadId = str_repeat('12', 16);

        $this->call('POST', '/import/upload-chunk', [
            'uploadId' => $uploadId,
            'offset' => '0',
            'fileName' => 'workbook.xlsx',
        ], [], [
            'chunk' => new UploadedFile($path, 'workbook.xlsx', 'application/octet-stream', null, true),
        ])->assertOk();

        Storage::disk(config('filesystems.default'))->assertExists('imports/stream-'.$uploadId.'.xlsx');

        $this->postJson('/tables/service-requests/import-classic/analyze', [
            'uploadId' => $uploadId,
            'originalName' => 'workbook.xlsx',
        ])
            ->assertOk()
            ->assertJsonPath('preview.sheet', 'Service Requests')
            ->assertJsonPath('preview.headerRow', 1)
            ->assertJsonPath('preview.totalRows', 1);

        Storage::disk(config('filesystems.default'))->delete('imports/stream-'.$uploadId.'.xlsx');
        @unlink($path);
    }

    /**
     * The classic wizard must also serve user-created (dynamic) tables: targets
     * come from their custom columns and execution goes through
     * DynamicTableImportService.
     */
    public function test_classic_wizard_imports_into_a_dynamic_table(): void
    {
        $user = User::factory()->superadmin()->create();
        $this->actingAs($user);

        $dynamic = DynamicTable::create([
            'key' => 'field-sites',
            'name' => 'Field Sites',
            'created_by' => $user->id,
        ]);

        $siteColumn = CustomTableColumn::create([
            'table_key' => $dynamic->key,
            'name' => 'Site',
            'type' => 'text',
            'position' => 0,
            'created_by' => $user->id,
        ]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sites');
        $sheet->setCellValue('A1', 'Name');
        $sheet->setCellValue('B1', 'Site');
        $sheet->setCellValue('A2', 'North Clinic');
        $sheet->setCellValue('B2', 'Bacolod');
        $path = tempnam(sys_get_temp_dir(), 'dynamic-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $uploadId = str_repeat('34', 16);

        $this->call('POST', '/import/upload-chunk', [
            'uploadId' => $uploadId,
            'offset' => '0',
            'fileName' => 'sites.xlsx',
        ], [], [
            'chunk' => new UploadedFile($path, 'sites.xlsx', 'application/octet-stream', null, true),
        ])->assertOk();

        $analysis = $this->postJson('/tables/'.$dynamic->key.'/import-classic/analyze', [
            'uploadId' => $uploadId,
            'originalName' => 'sites.xlsx',
        ])
            ->assertOk()
            ->assertJsonPath('preview.sheet', 'Sites')
            ->assertJsonPath('suggested.name', 'A')
            ->assertJsonPath('suggested.custom_'.$siteColumn->id, 'B')
            ->json();

        $execution = $this->postJson('/tables/'.$dynamic->key.'/import-classic/execute', [
            'uploadId' => $uploadId,
            'originalName' => 'sites.xlsx',
            'sheet' => $analysis['preview']['sheet'],
            'headerRow' => $analysis['preview']['headerRow'],
            'dataStart' => $analysis['preview']['dataStart'],
            'mapping' => [
                'name' => 'A',
                'custom_'.$siteColumn->id => 'B',
            ],
        ])
            ->assertOk()
            ->json();

        $this->assertSame('completed', $execution['status'], json_encode($execution));
        $this->assertSame(1, $execution['processed'], json_encode($execution));
        $this->assertSame(0, $execution['failed'], json_encode($execution));

        $this->assertDatabaseHas('dynamic_rows', [
            'table_key' => $dynamic->key,
            'name' => 'North Clinic',
        ]);

        $rowId = DynamicRow::query()->where('table_key', $dynamic->key)->value('id');

        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $siteColumn->id,
            'row_id' => $rowId,
            'value_text' => 'Bacolod',
        ]);

        Storage::disk(config('filesystems.default'))->delete('imports/stream-'.$uploadId.'.xlsx');
        @unlink($path);
    }

    /**
     * Formula cells must import the value Excel cached in the file — not the
     * raw "=..." formula string, and never a recalculation (that engine OOMs
     * the worker; see the Session 20 formula notes).
     */
    public function test_classic_wizard_imports_cached_formula_values_into_a_dynamic_table(): void
    {
        $user = User::factory()->superadmin()->create();
        $this->actingAs($user);

        $dynamic = DynamicTable::create([
            'key' => 'formula-sites',
            'name' => 'Formula Sites',
            'created_by' => $user->id,
        ]);

        $totalColumn = CustomTableColumn::create([
            'table_key' => $dynamic->key,
            'name' => 'Total',
            'type' => 'number',
            'position' => 0,
            'created_by' => $user->id,
        ]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Totals');
        $sheet->setCellValue('A1', 'Name');
        $sheet->setCellValue('B1', 'Base');
        $sheet->setCellValue('C1', 'Total');
        $sheet->setCellValue('A2', 'Formula Site');
        $sheet->setCellValue('B2', 100);
        $sheet->setCellValue('C2', '=B2*2');
        $path = tempnam(sys_get_temp_dir(), 'formula-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $uploadId = str_repeat('9a', 16);

        $this->call('POST', '/import/upload-chunk', [
            'uploadId' => $uploadId,
            'offset' => '0',
            'fileName' => 'totals.xlsx',
        ], [], [
            'chunk' => new UploadedFile($path, 'totals.xlsx', 'application/octet-stream', null, true),
        ])->assertOk();

        $analysis = $this->postJson('/tables/'.$dynamic->key.'/import-classic/analyze', [
            'uploadId' => $uploadId,
            'originalName' => 'totals.xlsx',
        ])
            ->assertOk()
            ->json();

        $computedColumn = collect($analysis['preview']['columns'])->firstWhere('letter', 'C');
        $this->assertContains('200', array_map('strval', $computedColumn['samples'] ?? []), json_encode($computedColumn));

        $execution = $this->postJson('/tables/'.$dynamic->key.'/import-classic/execute', [
            'uploadId' => $uploadId,
            'originalName' => 'totals.xlsx',
            'sheet' => $analysis['preview']['sheet'],
            'headerRow' => $analysis['preview']['headerRow'],
            'dataStart' => $analysis['preview']['dataStart'],
            'mapping' => [
                'name' => 'A',
                'custom_'.$totalColumn->id => 'C',
            ],
        ])
            ->assertOk()
            ->json();

        $this->assertSame('completed', $execution['status'], json_encode($execution));
        $this->assertSame(1, $execution['processed'], json_encode($execution));
        $this->assertSame(0, $execution['failed'], json_encode($execution));

        $rowId = DynamicRow::query()->where('table_key', $dynamic->key)->value('id');
        $stored = CustomTableColumnValue::query()
            ->where('custom_column_id', $totalColumn->id)
            ->where('row_id', $rowId)
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame(200.0, (float) $stored->value_number);

        Storage::disk(config('filesystems.default'))->delete('imports/stream-'.$uploadId.'.xlsx');
        @unlink($path);
    }

    /**
     * Regression: an analysis failure must return a JSON error. The catch
     * block used an unimported Str facade, so the JSON error itself crashed
     * into an HTML 500 — the browser then reported "Unexpected token '<'".
     */
    public function test_analyze_failure_returns_json_not_an_html_error_page(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Service Requests');
        $sheet->setCellValue('A1', 'SR No');
        $sheet->setCellValue('A2', 'SR-8001');
        $path = tempnam(sys_get_temp_dir(), 'json-error-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $uploadId = str_repeat('bc', 16);

        $this->call('POST', '/import/upload-chunk', [
            'uploadId' => $uploadId,
            'offset' => '0',
            'fileName' => 'workbook.xlsx',
        ], [], [
            'chunk' => new UploadedFile($path, 'workbook.xlsx', 'application/octet-stream', null, true),
        ])->assertOk();

        $response = $this->postJson('/tables/service-requests/import-classic/analyze', [
            'uploadId' => $uploadId,
            'originalName' => 'workbook.xlsx',
            'sheet' => 'Does not exist',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('could not be read', (string) $response->json('message'));
        $this->assertStringStartsNotWith('<!DOCTYPE', (string) $response->getContent());

        Storage::disk(config('filesystems.default'))->delete('imports/stream-'.$uploadId.'.xlsx');
        @unlink($path);
    }
}
