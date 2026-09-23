<?php

namespace Tests\Feature;

use App\Livewire\DynamicTable;
use App\Livewire\TablesList;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable as DynamicTableModel;
use App\Models\TableColumnPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TableColumnOverflowTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    private function createTable(string $name = 'Overflow Table'): DynamicTableModel
    {
        Livewire::actingAs($this->superadmin())
            ->test(TablesList::class)
            ->set('newTableName', $name)
            ->set('draftColumns', [
                ['name' => 'Status', 'type' => 'status'],
                ['name' => 'Due Date', 'type' => 'date'],
                ['name' => 'Brand', 'type' => 'text'],
            ])
            ->call('createTable')
            ->assertHasNoErrors();

        return DynamicTableModel::query()->where('name', $name)->firstOrFail();
    }

    private function columnKeys(DynamicTableModel $table): array
    {
        return CustomTableColumn::query()->where('table_key', $table->key)->orderBy('position')->pluck('name')->all();
    }

    private function customKey(DynamicTableModel $table, string $name): string
    {
        return CustomTableColumn::query()->where('table_key', $table->key)->where('name', $name)->firstOrFail()->columnKey();
    }

    public function test_insert_left_places_column_structurally_before_its_neighbor(): void
    {
        $owner = $this->superadmin();
        $table = $this->createTable();

        Livewire::actingAs($owner)
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('prefillAddColumn', $this->customKey($table, 'Due Date'), 'left')
            ->set('newColumnName', 'Priority')
            ->set('newColumnType', 'text')
            ->call('addCustomColumn')
            ->assertHasNoErrors();

        $this->assertSame(['Status', 'Priority', 'Due Date', 'Brand'], $this->columnKeys($table));
    }

    public function test_insert_right_places_column_after_its_neighbor_and_orders_user_prefs(): void
    {
        $owner = $this->superadmin();
        $table = $this->createTable();

        $component = Livewire::actingAs($owner)
            ->test(DynamicTable::class, ['table' => $table->key]);

        // Establish an explicit personal order first (reverse of structural).
        $structuralKeys = CustomTableColumn::query()->where('table_key', $table->key)->orderBy('position')->get()
            ->map(fn (CustomTableColumn $column): string => $column->columnKey())
            ->all();
        $reversed = array_reverse($structuralKeys);
        $component->call('saveColumnLayout', array_map(
            fn (string $key, int $index): array => ['key' => $key, 'position' => $index],
            $reversed,
            array_keys($reversed),
        ));

        $component
            ->call('prefillAddColumn', $this->customKey($table, 'Status'), 'right')
            ->set('newColumnName', 'Notes')
            ->set('newColumnType', 'text')
            ->call('addCustomColumn')
            ->assertHasNoErrors();

        $this->assertSame(['Status', 'Notes', 'Due Date', 'Brand'], $this->columnKeys($table));

        $prefOrder = TableColumnPreference::query()
            ->where('user_id', $owner->id)
            ->where('table_key', $table->key)
            ->orderBy('position')
            ->pluck('column_key')
            ->all();

        $newKey = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Notes')->firstOrFail()->columnKey();
        $statusKey = $this->customKey($table, 'Status');

        $this->assertSame($statusKey, $prefOrder[array_search($newKey, $prefOrder, true) - 1]);
    }

    public function test_insert_with_unknown_neighbor_appends(): void
    {
        $table = $this->createTable();

        Livewire::actingAs($this->superadmin())
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('prefillAddColumn', 'custom_999999', 'left')
            ->set('newColumnName', 'Appended')
            ->set('newColumnType', 'text')
            ->call('addCustomColumn')
            ->assertHasNoErrors();

        $this->assertSame(['Status', 'Due Date', 'Brand', 'Appended'], $this->columnKeys($table));
    }

    public function test_prefill_rejects_bad_side_and_unknown_neighbor(): void
    {
        $table = $this->createTable();

        $component = Livewire::actingAs($this->superadmin())
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('prefillAddColumn', $this->customKey($table, 'Status'), 'sideways')
            ->assertSet('insertNeighborKey', null)
            ->call('prefillAddColumn', 'custom_999999', 'left')
            ->assertSet('insertNeighborKey', null);
    }

    public function test_duplicate_copies_definition_values_and_sits_next_to_source(): void
    {
        $owner = $this->superadmin();
        $table = $this->createTable();

        $status = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Status')->firstOrFail();
        $row = DynamicRow::create(['table_key' => $table->key, 'name' => 'Row 1', 'source_system' => 'test', 'source_record_id' => '1']);
        CustomTableColumnValue::create([
            'custom_column_id' => $status->id,
            'row_id' => $row->id,
            'value' => ['index' => 1, 'label' => 'Done'],
            'value_text' => 'Done',
            'value_number' => 1,
        ]);

        Livewire::actingAs($owner)
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('duplicateCustomColumn', $status->id)
            ->assertHasNoErrors();

        $this->assertSame(['Status', 'Status (copy)', 'Due Date', 'Brand'], $this->columnKeys($table));

        $copy = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Status (copy)')->firstOrFail();
        $this->assertSame($status->type, $copy->type);
        $this->assertSame($status->settings, $copy->settings);

        $copied = CustomTableColumnValue::query()->where('custom_column_id', $copy->id)->firstOrFail();
        $this->assertSame($row->id, $copied->row_id);
        $this->assertSame('Done', $copied->value_text);

        // A second duplicate gets a unique name.
        Livewire::actingAs($owner)
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('duplicateCustomColumn', $status->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('table_custom_columns', ['table_key' => $table->key, 'name' => 'Status (copy 2)']);
    }

    public function test_move_to_edge_reorders_only_the_acting_user(): void
    {
        $owner = $this->superadmin();
        $other = $this->superadmin();
        $table = $this->createTable();

        $brandKey = $this->customKey($table, 'Brand');

        Livewire::actingAs($owner)
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('moveColumnToEdge', $brandKey, 'start')
            ->assertHasNoErrors();

        $ownerOrder = TableColumnPreference::query()
            ->where('user_id', $owner->id)
            ->where('table_key', $table->key)
            ->orderBy('position')
            ->pluck('column_key')
            ->all();

        $this->assertSame($brandKey, $ownerOrder[0]);

        $this->assertFalse(TableColumnPreference::query()
            ->where('user_id', $other->id)
            ->where('table_key', $table->key)
            ->exists());

        Livewire::actingAs($owner)
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('moveColumnToEdge', 'nope', 'start')
            ->call('moveColumnToEdge', $brandKey, 'sideways')
            ->assertHasNoErrors();
    }

    public function test_clear_empties_values_but_keeps_the_column(): void
    {
        $owner = $this->superadmin();
        $table = $this->createTable();

        $brand = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Brand')->firstOrFail();
        $row = DynamicRow::create(['table_key' => $table->key, 'name' => 'Row 1', 'source_system' => 'test', 'source_record_id' => '1']);
        CustomTableColumnValue::create([
            'custom_column_id' => $brand->id,
            'row_id' => $row->id,
            'value' => ['text' => 'Acme'],
            'value_text' => 'Acme',
        ]);

        Livewire::actingAs($owner)
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('clearCustomColumnValues', $brand->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('table_custom_columns', ['id' => $brand->id]);
        $this->assertDatabaseMissing('table_custom_column_values', ['custom_column_id' => $brand->id]);
    }

    public function test_overflow_menu_sort_sets_direction_explicitly_and_clears(): void
    {
        $table = $this->createTable();

        Livewire::actingAs($this->superadmin())
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('setSort', $this->customKey($table, 'Brand'), 'desc')
            ->assertSet('sortField', $this->customKey($table, 'Brand'))
            ->assertSet('sortDirection', 'desc')
            ->call('setSort', $this->customKey($table, 'Brand'), 'sideways')
            ->assertSet('sortDirection', 'asc')
            ->call('clearSort')
            ->assertSet('sortField', null);

        // Sorting is view state: viewers may sort too.
        Livewire::actingAs(User::factory()->president()->create())
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('setSort', $this->customKey($table, 'Brand'), 'asc')
            ->assertHasNoErrors();
    }

    public function test_viewers_cannot_use_structural_column_actions(): void
    {
        $table = $this->createTable();
        $status = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Status')->firstOrFail();

        $viewer = User::factory()->president()->create();

        Livewire::actingAs($viewer)
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('prefillAddColumn', $status->columnKey(), 'left')
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('duplicateCustomColumn', $status->id)
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('clearCustomColumnValues', $status->id)
            ->assertForbidden();
    }
}
