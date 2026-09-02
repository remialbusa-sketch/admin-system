<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\DynamicTable;
use App\Models\CustomTableColumn;
use App\Models\DynamicRow;
use App\Models\DynamicTable as DynamicTableModel;
use App\Models\MondaySyncSetting;
use App\Models\User;
use App\Services\MondayItemMapper;
use App\Services\MondaySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MondayItemMapperTest extends TestCase
{
    use RefreshDatabase;

    private function makeTable(string $key = 'equipment'): DynamicTableModel
    {
        return DynamicTableModel::create([
            'key' => $key,
            'name' => ucfirst($key),
            'monday_board_id' => '123',
            'created_by' => null,
        ]);
    }

    public function test_auto_create_columns_from_board_with_type_map(): void
    {
        $table = $this->makeTable();
        $mapper = app(MondayItemMapper::class);

        $created = $mapper->autoCreateColumns($table, [
            ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => '{"labels":{"0":"New","1":"In Progress","2":"Done"}}'],
            ['id' => 'text9', 'title' => 'Cancel Count', 'type' => 'numbers'],
            ['id' => 'date4', 'title' => 'Due Date', 'type' => 'date'],
            ['id' => 'char8', 'title' => 'Notes', 'type' => 'long_text'],
        ]);

        $this->assertSame(4, $created);
        $this->assertSame('status', CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Status')->value('type'));
        $this->assertSame('number', CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Cancel Count')->value('type'));

        $map = $table->fresh()->monday_field_map;
        $this->assertArrayHasKey('text9', $map);
        $this->assertArrayHasKey('status', $map);
    }

    public function test_maps_item_into_dynamic_row_with_column_values(): void
    {
        $table = $this->makeTable();
        $mapper = app(MondayItemMapper::class);
        $mapper->autoCreateColumns($table, [
            ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => '{"labels":{"0":"New","1":"In Progress","2":"Done"}}'],
            ['id' => 'numbers7', 'title' => 'Units', 'type' => 'numbers'],
            ['id' => 'date4', 'title' => 'Due Date', 'type' => 'date'],
        ]);

        $map = $table->fresh()->monday_field_map;
        $statusCol = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Status')->first();
        $unitsCol = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Units')->first();
        $dateCol = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Due Date')->first();

        $ok = $mapper->mapItem($table, [
            'id' => '1001',
            'name' => 'MRI Unit',
            'updated_at' => '2026-09-02T00:00:00Z',
            'column_values' => [
                ['id' => 'status', 'text' => 'Done', 'type' => 'status', 'label' => 'Done'],
                ['id' => 'numbers7', 'text' => '4', 'type' => 'numbers', 'number' => 4],
                ['id' => 'date4', 'text' => '2026-08-26', 'type' => 'date', 'date' => '2026-08-26'],
            ],
        ]);

        $this->assertTrue($ok);

        $row = DynamicRow::query()->where('table_key', $table->key)->where('source_record_id', '1001')->firstOrFail();
        $this->assertSame('monday:'.$table->key, $row->source_system);
        $this->assertSame('MRI Unit', $row->name);

        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $statusCol->id,
            'row_id' => $row->id,
            'value_text' => 'Done',
        ]);
        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $unitsCol->id,
            'row_id' => $row->id,
            'value_number' => 4,
        ]);
        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $dateCol->id,
            'row_id' => $row->id,
            'value_date' => '2026-08-26',
        ]);
    }

    public function test_connect_board_auto_creates_columns_and_sync_imports(): void
    {
        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);

        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $table = $this->makeTable();

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'settings_str')) {
                    return Http::response([
                        'data' => ['boards' => [['id' => '123', 'columns' => [
                            ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => '{"labels":{"0":"New","1":"In Progress","2":"Done"}}'],
                            ['id' => 'text9', 'title' => 'Brand', 'type' => 'text'],
                        ]]]],
                    ]);
                }

                if (str_contains($query, 'items_page')) {
                    return Http::response([
                        'data' => ['boards' => [['id' => '123', 'items_page' => ['cursor' => null, 'items' => [
                            ['id' => '50', 'name' => 'X', 'created_at' => null, 'updated_at' => null],
                        ]]]]],
                    ]);
                }

                if (str_contains($query, 'query Items')) {
                    return Http::response([
                        'data' => ['items' => [[
                            'id' => '50',
                            'name' => 'Device X',
                            'created_at' => null,
                            'updated_at' => null,
                            'column_values' => [
                                ['id' => 'status', 'text' => 'Done', 'type' => 'status', 'label' => 'Done'],
                                ['id' => 'text9', 'text' => 'GE', 'type' => 'text'],
                            ],
                        ]]],
                    ]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        // 1. Connect the board through the page — should auto-create columns.
        Livewire::actingAs($user)
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('openConnectBoard')
            ->set('connectBoardId', '123')
            ->call('connectBoard');

        $this->assertSame('123', $table->fresh()->monday_board_id);
        $this->assertSame(2, CustomTableColumn::query()->where('table_key', $table->key)->count());

        // 2. Give the sync setting a board so sync can run; then trigger the import.
        $setting = MondaySyncSetting::forDomain($table->key);
        $setting->update(['board_id' => '123', 'enabled' => true]);

        $result = app(MondaySyncService::class)->syncDomain($table->key);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['imported']);

        $row = DynamicRow::query()->where('table_key', $table->key)->where('source_record_id', '50')->firstOrFail();
        $this->assertSame('Device X', $row->name);
    }

    public function test_sync_now_button_runs_pull_and_reports_result(): void
    {
        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);

        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $table = $this->makeTable('fleet');

        // Give the table a mapped text column so mapItem succeeds.
        CustomTableColumn::create(['table_key' => 'fleet', 'name' => 'Brand', 'type' => 'text', 'position' => 0]);
        $colId = CustomTableColumn::query()->where('table_key', 'fleet')->where('name', 'Brand')->value('id');
        $table->update(['monday_field_map' => ['text9' => $colId]]);
        MondaySyncSetting::updateOrCreate(['domain' => 'fleet'], ['board_id' => '123', 'enabled' => true]);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'items_page')) {
                    return Http::response([
                        'data' => ['boards' => [['id' => '123', 'items_page' => ['cursor' => null, 'items' => [
                            ['id' => '60', 'name' => 'Toyota', 'created_at' => null, 'updated_at' => null],
                        ]]]]],
                    ]);
                }

                if (str_contains($query, 'query Items')) {
                    return Http::response([
                        'data' => ['items' => [[
                            'id' => '60',
                            'name' => 'Toyota',
                            'created_at' => null,
                            'updated_at' => null,
                            'column_values' => [['id' => 'text9', 'text' => 'GE', 'type' => 'text']],
                        ]]],
                    ]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        $component = Livewire::actingAs($user)->test(DynamicTable::class, ['table' => 'fleet']);
        $component->call('syncNow');

        // The item was imported through the manual sync.
        $row = DynamicRow::query()->where('table_key', 'fleet')->where('source_record_id', '60')->firstOrFail();
        $this->assertSame('Toyota', $row->name);

        // Sync status was refreshed.
        $this->assertNotNull(MondaySyncSetting::forDomain('fleet')->last_synced_at);
    }
}
