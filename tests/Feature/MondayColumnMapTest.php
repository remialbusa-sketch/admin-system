<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\DynamicTable;
use App\Models\CustomTableColumn;
use App\Models\DynamicTable as DynamicTableModel;
use App\Models\MondaySyncSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Map columns" editor on the shared monday panel: list every board
 * column with its current table mapping, remap / unmap / create-new on save,
 * and reject ids from other tables.
 */
class MondayColumnMapTest extends TestCase
{
    use RefreshDatabase;

    private function makeConnectedTable(array $fieldMap = [], ?string $boardId = '123'): DynamicTableModel
    {
        $table = DynamicTableModel::create([
            'key' => 'service-requests',
            'name' => 'Service Requests',
            'monday_board_id' => $boardId,
            'created_by' => null,
        ]);

        MondaySyncSetting::updateOrCreate(['domain' => 'service-requests'], [
            'board_id' => $boardId,
            'enabled' => true,
            'field_map' => $fieldMap,
        ]);

        return $table;
    }

    private function fakeBoardColumns(): void
    {
        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'settings_str')) {
                    return Http::response([
                        'data' => ['boards' => [['id' => '123', 'columns' => [
                            ['id' => 'text9', 'title' => 'BRAND', 'type' => 'dropdown', 'settings_str' => '{"labels":[{"id":1,"name":"AEONMED"}]}'],
                            ['id' => 'status', 'title' => 'Ticket Status', 'type' => 'status', 'settings_str' => '{"labels":{"0":"OPEN","1":"CLOSED"}}'],
                            ['id' => 'dd2', 'title' => 'Peripherals', 'type' => 'dropdown', 'settings_str' => '{"labels":[{"id":1,"name":"Keyboard"}]}'],
                        ]]]],
                    ]);
                }

                return Http::response(['data' => []]);
            },
        ]);
    }

    private function tableComponent(): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::Superadmin]))
            ->test(DynamicTable::class, ['table' => 'service-requests']);
    }

    public function test_map_columns_lists_board_columns_with_the_current_mapping(): void
    {
        $this->makeConnectedTable();
        $brand = CustomTableColumn::create([
            'table_key' => 'service-requests',
            'name' => 'Brand',
            'type' => 'text',
            'position' => 0,
        ]);
        MondaySyncSetting::forDomain('service-requests')->update(['field_map' => ['text9' => $brand->id]]);
        $this->fakeBoardColumns();

        $rows = $this->tableComponent()->call('openMapColumns')->get('mapColumns');

        $this->assertCount(3, $rows);
        $this->assertSame('text9', $rows[0]['id']);
        $this->assertSame((string) $brand->id, $rows[0]['local']);
        $this->assertSame('Ticket Status', $rows[1]['title']);
        $this->assertSame('', $rows[1]['local']);
        $this->assertSame('', $rows[2]['local']);
    }

    public function test_map_columns_requires_a_connected_board(): void
    {
        $this->makeConnectedTable([], boardId: null);
        $this->fakeBoardColumns();

        $component = $this->tableComponent()->call('openMapColumns');

        $component->assertSet('mondayMessageTone', 'error');
        $this->assertSame([], $component->get('mapColumns'));
    }

    public function test_saving_the_map_drops_unmapped_and_keeps_other_entries(): void
    {
        $brand = CustomTableColumn::create(['table_key' => 'service-requests', 'name' => 'Brand', 'type' => 'text', 'position' => 0]);
        $ticket = CustomTableColumn::create(['table_key' => 'service-requests', 'name' => 'Ticket Status', 'type' => 'status', 'position' => 1]);
        $this->makeConnectedTable(['text9' => $brand->id, 'status' => $ticket->id]);
        $this->fakeBoardColumns();

        $component = $this->tableComponent()->call('openMapColumns');
        $rows = $component->get('mapColumns');
        $rows[0]['local'] = '';
        $component->set('mapColumns', $rows);
        $component->call('saveColumnMap');

        $component->assertSet('showMapColumnsModal', false);
        $component->assertSet('mondayMessageTone', 'success');

        $map = MondaySyncSetting::forDomain('service-requests')->fresh()->field_map;
        $this->assertArrayNotHasKey('text9', $map);
        $this->assertSame($ticket->id, $map['status']);

        // The dynamic-table mirror stays in step with the canonical map.
        $mirror = DynamicTableModel::query()->where('key', 'service-requests')->first()->monday_field_map;
        $this->assertArrayNotHasKey('text9', $mirror);
        $this->assertSame($ticket->id, $mirror['status']);
    }

    public function test_saving_the_map_can_create_a_missing_column_exactly_once(): void
    {
        $this->makeConnectedTable();
        $this->fakeBoardColumns();

        $component = $this->tableComponent()->call('openMapColumns');
        $rows = $component->get('mapColumns');
        $rows[2]['local'] = '__new__';
        $rows[2]['newName'] = 'Peripherals';
        $component->set('mapColumns', $rows);
        $component->call('saveColumnMap');

        $column = CustomTableColumn::query()
            ->where('table_key', 'service-requests')
            ->where('name', 'Peripherals')
            ->firstOrFail();
        $this->assertSame('dropdown', $column->type);
        $this->assertSame($column->id, MondaySyncSetting::forDomain('service-requests')->fresh()->field_map['dd2']);

        // Saving again with the same "create new" choice (case-variant name)
        // reuses it through the shared boundary — never a duplicate.
        $rows[2]['newName'] = 'peripherals';
        $component->set('mapColumns', $rows);
        $component->call('saveColumnMap');

        $this->assertSame(1, CustomTableColumn::query()->where('table_key', 'service-requests')->count());
        $this->assertSame($column->id, MondaySyncSetting::forDomain('service-requests')->fresh()->field_map['dd2']);
    }

    public function test_saving_the_map_rejects_a_column_from_another_table(): void
    {
        $brand = CustomTableColumn::create(['table_key' => 'service-requests', 'name' => 'Brand', 'type' => 'text', 'position' => 0]);
        $this->makeConnectedTable(['text9' => $brand->id]);
        $foreign = CustomTableColumn::create(['table_key' => 'other-table', 'name' => 'Secret', 'type' => 'text', 'position' => 0]);
        $this->fakeBoardColumns();

        $component = $this->tableComponent()->call('openMapColumns');
        $rows = $component->get('mapColumns');
        $rows[0]['local'] = (string) $foreign->id;
        $component->set('mapColumns', $rows);
        $component->call('saveColumnMap');

        $component->assertSet('mondayMessageTone', 'error');

        // Nothing was written.
        $map = MondaySyncSetting::forDomain('service-requests')->fresh()->field_map;
        $this->assertSame($brand->id, $map['text9']);
    }
}
