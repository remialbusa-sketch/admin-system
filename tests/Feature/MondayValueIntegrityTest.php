<?php

namespace Tests\Feature;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\MondaySyncSetting;
use App\Models\TechnicalReport;
use App\Services\ImportMappingService;
use App\Services\MondayItemMapper;
use App\Services\SourceWorkbookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MondayValueIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_does_not_suggest_signature_for_technician_name(): void
    {
        $service = app(ImportMappingService::class);
        $mapping = $service->suggestMapping(ImportMappingService::TARGETS['technical-reports']['fields'], [
            ['letter' => 'A', 'label' => 'TSP ASSIGNED'],
            ['letter' => 'B', 'label' => 'TSP Signature'],
        ]);
        $this->assertSame('A', $mapping['tsp_name'] ?? null);
        $this->assertArrayNotHasKey('tsp', $mapping);
    }

    public function test_import_never_uses_signature_url_as_display_name(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tsp-integrity-').'.csv';
        file_put_contents($path, "Reference Number,TSP Name,TSP\nR42,Fidel Carino,https://example.com/signature.png\n");
        try {
            $batch = app(SourceWorkbookImportService::class)->importTechnicalReports($path);
            $this->assertSame('completed', $batch->status);
            $report = TechnicalReport::firstOrFail();
            $this->assertSame('Fidel Carino', $report->tsp_display_name);
        } finally {
            @unlink($path);
        }
    }

    public function test_signature_column_cannot_win_over_assigned_technician(): void
    {
        $mapper = app(MondayItemMapper::class);
        $mapper->autoCreateColumns('technical-reports', [
            ['id' => 'signature', 'title' => 'TSP', 'type' => 'file'],
            ['id' => 'technician', 'title' => 'TSP Assigned', 'type' => 'people'],
        ]);

        $this->assertSame(['technician' => 'tsp_name'], MondaySyncSetting::forDomain('technical-reports')->core_field_map);
    }

    public function test_sync_replaces_stale_display_name_and_uses_column_ids_despite_order(): void
    {
        $mapper = app(MondayItemMapper::class);
        $mapper->autoCreateColumns('technical-reports', [
            ['id' => 'technician', 'title' => 'TSP Assigned', 'type' => 'people'],
            ['id' => 'brand', 'title' => 'Brand', 'type' => 'text'],
        ]);
        TechnicalReport::create([
            'source_system' => 'monday:technical-reports', 'source_record_id' => '42',
            'reference_number' => 'R42', 'tsp_name' => 'Old name',
            'tsp_display_name' => 'https://example.com/signature.png',
        ]);

        $this->assertTrue($mapper->mapItem('technical-reports', [
            'id' => '42', 'name' => 'R42', 'column_values' => [
                ['id' => 'brand', 'type' => 'text', 'text' => 'Sysmex'],
                ['id' => 'technician', 'type' => 'people', 'text' => 'Fidel Carino'],
            ],
        ]));
        $report = TechnicalReport::firstOrFail();
        $this->assertSame('Fidel Carino', $report->tsp_display_name);
        $this->assertSame('Fidel Carino', $report->tsp_name);
        $this->assertSame('Sysmex', $report->brand);
    }

    public function test_file_assets_keep_their_identity_and_image_metadata(): void
    {
        $mapper = app(MondayItemMapper::class);
        $mapper->autoCreateColumns('technical-reports', [
            ['id' => 'signature', 'title' => 'Signature', 'type' => 'file'],
        ]);
        $this->assertTrue($mapper->mapItem('technical-reports', [
            'id' => '42', 'name' => 'R42', 'column_values' => [[
                'id' => 'signature', 'type' => 'file', 'text' => '',
                'files' => [['asset_id' => '123', 'name' => 'signature.png', 'is_image' => true]],
            ]],
        ]));
        $column = CustomTableColumn::where('name', 'Signature')->firstOrFail();
        $this->assertSame('files', $column->type);
        $value = CustomTableColumnValue::where('custom_column_id', $column->id)->firstOrFail()->value;
        $this->assertSame(123, $value['files'][0]['asset_id'] ?? null);
        $this->assertTrue($value['files'][0]['is_image'] ?? false);
    }

    public function test_typed_values_do_not_depend_on_display_text(): void
    {
        $mapper = app(MondayItemMapper::class);
        $mapper->autoCreateColumns('technical-reports', [
            ['id' => 'link', 'title' => 'Report Link', 'type' => 'link'],
            ['id' => 'number', 'title' => 'Hours', 'type' => 'numbers'],
            ['id' => 'location', 'title' => 'Site', 'type' => 'location'],
        ]);
        $this->assertTrue($mapper->mapItem('technical-reports', [
            'id' => '42', 'name' => 'R42', 'column_values' => [
                ['id' => 'link', 'type' => 'link', 'text' => 'View report', 'value' => json_encode(['url' => 'https://example.com/report', 'text' => 'View report'])],
                ['id' => 'number', 'type' => 'numbers', 'text' => '', 'number' => 0],
                ['id' => 'location', 'type' => 'location', 'text' => '', 'value' => json_encode(['lat' => 14.5, 'lng' => 120.5, 'address' => 'Manila'])],
            ],
        ]));
        $values = CustomTableColumnValue::get()->keyBy('custom_column_id');
        foreach (CustomTableColumn::get() as $column) {
            $value = $values[$column->id]->value;
            match ($column->name) {
                'Report Link' => $this->assertSame('https://example.com/report', $value['url']),
                'Hours' => $this->assertEquals(0, $value['number']),
                'Site' => $this->assertSame('Manila', $value['address']),
            };
        }
    }

    public function test_old_file_mapping_is_promoted_without_losing_the_image(): void
    {
        $column = CustomTableColumn::create(['table_key' => 'technical-reports', 'name' => 'Photo', 'type' => 'text', 'position' => 1]);
        MondaySyncSetting::create(['domain' => 'technical-reports', 'field_map' => ['f' => $column->id], 'core_field_map' => []]);
        $this->assertTrue(app(MondayItemMapper::class)->mapItem('technical-reports', [
            'id' => '42', 'name' => 'R42', 'column_values' => [['id' => 'f', 'type' => 'file', 'text' => '', 'files' => [['asset_id' => '123', 'name' => 'photo.png', 'is_image' => true]]]],
        ]));
        $this->assertSame('files', $column->fresh()->type);
        $this->assertSame(123, CustomTableColumnValue::firstOrFail()->value['files'][0]['asset_id']);
    }

    public function test_incompatible_saved_mapping_rolls_back_instead_of_overwriting_a_person(): void
    {
        $column = CustomTableColumn::create(['table_key' => 'technical-reports', 'name' => 'Signature', 'type' => 'files', 'position' => 1]);
        MondaySyncSetting::create(['domain' => 'technical-reports', 'field_map' => ['f' => $column->id], 'core_field_map' => ['f' => 'tsp_name']]);
        $report = TechnicalReport::create(['source_system' => 'monday:technical-reports', 'source_record_id' => '42', 'reference_number' => 'R42', 'tsp_name' => 'Correct technician']);
        $this->assertFalse(app(MondayItemMapper::class)->mapItem('technical-reports', [
            'id' => '42', 'name' => 'Changed title', 'column_values' => [['id' => 'f', 'type' => 'file', 'text' => '', 'files' => []]],
        ]));
        $this->assertSame('Correct technician', $report->fresh()->tsp_name);
        $this->assertSame('R42', $report->fresh()->reference_number);
    }

    public function test_same_named_file_and_person_sources_have_separate_destinations(): void
    {
        app(MondayItemMapper::class)->autoCreateColumns('technical-reports', [
            ['id' => 'signature', 'title' => 'TSP', 'type' => 'file'],
            ['id' => 'technician', 'title' => 'TSP', 'type' => 'people'],
        ]);
        $setting = MondaySyncSetting::forDomain('technical-reports');
        $this->assertNotSame($setting->field_map['signature'], $setting->field_map['technician']);
        $this->assertSame(['technician' => 'tsp_name'], $setting->core_field_map);
    }
}
