<?php

namespace Tests\Feature;

use App\Models\CustomTableColumn;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Core-table replace imports: the file's columns become the table's custom
 * columns — stale ones from previous files are dropped, type picks made in
 * step 3 are honored on connected columns, and files-type columns land as
 * text (workbooks cannot carry uploads) with a visible notice.
 */
class CoreImportReplaceTest extends TestCase
{
    use RefreshDatabase;

    /** SR No + Customer + one extra column workbook. */
    private function streamThreeColumnWorkbook(string $uploadId, string $extraHeader, string $extraValue): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Service Requests');
        $sheet->setCellValue('A1', 'SR No');
        $sheet->setCellValue('B1', 'Customer');
        $sheet->setCellValue('C1', $extraHeader);
        $sheet->setCellValue('A2', 'SR-9001');
        $sheet->setCellValue('B2', 'Extra Hospital');
        $sheet->setCellValue('C2', $extraValue);
        $path = tempnam(sys_get_temp_dir(), 'core-').'.xlsx';
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

    private function basePayload(string $uploadId, array $mapping, array $newColumns = [], array $extra = []): array
    {
        return array_merge([
            'uploadId' => $uploadId,
            'originalName' => 'workbook.xlsx',
            'sheet' => 'Service Requests',
            'headerRow' => 1,
            'dataStart' => 2,
            'mapping' => $mapping,
            'newColumns' => $newColumns,
        ], $extra);
    }

    public function test_stale_custom_columns_are_dropped_on_core_reimport(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        CustomTableColumn::create([
            'table_key' => 'service-requests',
            'name' => 'Old Stuff',
            'type' => 'text',
            'settings' => [],
            'position' => 0,
            'created_by' => null,
        ]);

        $uploadId = str_repeat('aa', 16);
        $this->streamThreeColumnWorkbook($uploadId, 'Note', 'hello');

        $execution = $this->postJson(
            '/tables/service-requests/import-classic/execute',
            $this->basePayload($uploadId, ['service_request_no' => 'A', 'customer_name' => 'B'], [
                ['letter' => 'C', 'name' => 'Note', 'type' => 'text'],
            ])
        )->assertOk()->json();

        $this->assertSame('completed', $execution['status'], json_encode($execution));
        $this->assertSame(1, $execution['processed'], json_encode($execution));

        // The previous file's column is gone; the new file's column is here.
        $this->assertDatabaseMissing('table_custom_columns', [
            'table_key' => 'service-requests',
            'name' => 'Old Stuff',
        ]);
        $this->assertDatabaseHas('table_custom_columns', [
            'table_key' => 'service-requests',
            'name' => 'Note',
        ]);
    }

    public function test_connected_custom_column_type_pick_is_applied(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $priority = CustomTableColumn::create([
            'table_key' => 'service-requests',
            'name' => 'Priority',
            'type' => 'text',
            'settings' => [],
            'position' => 0,
            'created_by' => null,
        ]);

        $uploadId = str_repeat('bb', 16);
        $this->streamThreeColumnWorkbook($uploadId, 'Priority', 'High');

        $execution = $this->postJson(
            '/tables/service-requests/import-classic/execute',
            $this->basePayload(
                $uploadId,
                ['service_request_no' => 'A', 'customer_name' => 'B', $priority->columnKey() => 'C'],
                [],
                // Step 3 retyped the connected column to status.
                ['columnTypes' => ['C' => 'status']]
            )
        )->assertOk()->json();

        $this->assertSame('completed', $execution['status'], json_encode($execution));
        $this->assertSame(1, $execution['processed'], json_encode($execution));
        $this->assertSame(0, $execution['failed'], json_encode($execution));

        $this->assertSame('status', $priority->fresh()->type);
        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $priority->id,
            'value_text' => 'High',
        ]);
    }

    public function test_files_columns_import_as_text_with_notice(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $uploadId = str_repeat('cc', 16);
        $this->streamThreeColumnWorkbook($uploadId, 'Attachment', 'report.pdf');

        $execution = $this->postJson(
            '/tables/service-requests/import-classic/execute',
            $this->basePayload($uploadId, ['service_request_no' => 'A', 'customer_name' => 'B'], [
                ['letter' => 'C', 'name' => 'Attachment', 'type' => 'files'],
            ])
        )->assertOk()->json();

        // The data lands as text instead of failing the row.
        $this->assertSame('completed', $execution['status'], json_encode($execution));
        $this->assertSame(1, $execution['processed'], json_encode($execution));
        $this->assertSame(0, $execution['failed'], json_encode($execution));

        $column = CustomTableColumn::query()
            ->where('table_key', 'service-requests')
            ->where('name', 'Attachment')
            ->firstOrFail();

        $this->assertSame('text', $column->type);

        $rowId = ServiceRequest::query()->where('service_request_number', 'SR-9001')->value('id');
        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $column->id,
            'row_id' => $rowId,
            'value_text' => 'report.pdf',
        ]);

        $this->assertNotEmpty($execution['notices'] ?? []);
        $this->assertStringContainsString('text', strtolower(implode(' ', $execution['notices'])));
    }
}
