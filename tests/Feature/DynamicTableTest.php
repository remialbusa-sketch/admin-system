<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\DynamicTable;
use App\Livewire\TablesList;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable as DynamicTableModel;
use App\Models\MondaySyncSetting;
use App\Models\User;
use App\Services\DynamicTableImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DynamicTableTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->create(['role' => UserRole::Superadmin]);
    }

    private function createTableWithColumns(string $name = 'Equipment Requests', array $columns = [['name' => 'Status', 'type' => 'status'], ['name' => 'Due Date', 'type' => 'date']]): DynamicTableModel
    {
        Livewire::actingAs($this->superadmin())
            ->test(TablesList::class)
            ->set('newTableName', $name)
            ->set('draftColumns', $columns)
            ->call('createTable');

        return DynamicTableModel::query()->where('name', $name)->firstOrFail();
    }

    public function test_superadmin_can_create_a_table_with_defined_columns(): void
    {
        $table = $this->createTableWithColumns('Equipment Requests', [
            ['name' => 'Status', 'type' => 'status'],
            ['name' => 'Due Date', 'type' => 'date'],
            ['name' => 'Brand', 'type' => 'text'],
        ]);

        $this->assertSame('equipment-requests', $table->key);

        $names = CustomTableColumn::query()->where('table_key', $table->key)->orderBy('position')->pluck('name')->all();
        $this->assertSame(['Status', 'Due Date', 'Brand'], $names);
    }

    public function test_create_table_rejects_duplicate_column_names(): void
    {
        Livewire::actingAs($this->superadmin())
            ->test(TablesList::class)
            ->set('newTableName', 'Bad')
            ->set('draftColumns', [['name' => 'Status', 'type' => 'status'], ['name' => 'Status', 'type' => 'text']])
            ->call('createTable')
            ->assertHasErrors(['draftColumns']);

        $this->assertDatabaseMissing('dynamic_tables', ['name' => 'Bad']);
    }

    public function test_only_superadmin_can_create_a_table(): void
    {
        Livewire::actingAs(User::factory()->president()->create())
            ->test(TablesList::class)
            ->set('newTableName', 'Nope')
            ->call('createTable')
            ->assertForbidden();
    }

    public function test_dynamic_table_page_renders_monday_panel_and_custom_columns(): void
    {
        $table = $this->createTableWithColumns();

        Livewire::actingAs($this->superadmin())
            ->test(DynamicTable::class, ['table' => $table->key])
            ->assertOk()
            ->assertSee('Equipment Requests')
            ->assertSee('monday.com live sync')
            ->assertSee('Status');
    }

    public function test_row_can_be_created_and_custom_field_updated(): void
    {
        $table = $this->createTableWithColumns();
        $statusCol = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Status')->first();
        $dateCol = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Due Date')->first();

        Livewire::actingAs($this->superadmin())
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('createRecord', ['name' => 'MRI Unit'])
            ->assertOk();

        $row = DynamicRow::query()->where('table_key', $table->key)->where('name', 'MRI Unit')->firstOrFail();
        $this->assertSame('manual', $row->source_system);

        Livewire::actingAs($this->superadmin())
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('updateCustomField', $row->id, $statusCol->id, 'Done')
            ->call('updateCustomField', $row->id, $dateCol->id, '2026-08-26');

        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $statusCol->id,
            'row_id' => $row->id,
            'value_text' => 'Done',
        ]);
        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $dateCol->id,
            'row_id' => $row->id,
            'value_date' => '2026-08-26',
        ]);
    }

    public function test_delete_purges_custom_values(): void
    {
        $table = $this->createTableWithColumns();
        $statusCol = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Status')->first();

        $row = DynamicRow::create([
            'table_key' => $table->key,
            'name' => 'Delete me',
            'source_system' => 'manual',
            'source_record_id' => 'manual-delete-me',
        ]);

        CustomTableColumnValue::create([
            'custom_column_id' => $statusCol->id,
            'row_id' => $row->id,
            'value' => ['label' => 'Done'],
            'value_text' => 'Done',
        ]);

        Livewire::actingAs($this->superadmin())
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('deleteRecord', $row->id);

        $this->assertDatabaseMissing('dynamic_rows', ['id' => $row->id]);
        $this->assertDatabaseMissing('table_custom_column_values', ['custom_column_id' => $statusCol->id, 'row_id' => $row->id]);
    }

    public function test_toggle_monday_pull_flips_sync_setting(): void
    {
        $table = $this->createTableWithColumns();

        $component = Livewire::actingAs($this->superadmin())
            ->test(DynamicTable::class, ['table' => $table->key]);

        $component->call('toggleMondayPull');
        $this->assertTrue(MondaySyncSetting::forDomain($table->key)->enabled);

        $component->call('toggleMondayPull');
        $this->assertFalse(
            (bool) MondaySyncSetting::query()->where('domain', $table->key)->value('enabled'),
        );
    }

    public function test_connect_board_stores_board_id(): void
    {
        $table = $this->createTableWithColumns();

        Livewire::actingAs($this->superadmin())
            ->test(DynamicTable::class, ['table' => $table->key])
            ->call('openConnectBoard')
            ->set('connectBoardId', '1771812698')
            ->call('connectBoard');

        $this->assertSame('1771812698', $table->fresh()->monday_board_id);
        $this->assertSame('1771812698', MondaySyncSetting::forDomain($table->key)->board_id);
    }

    public function test_import_backfills_rows_idempotently(): void
    {
        $table = $this->createTableWithColumns('Backfill', [
            ['name' => 'Brand', 'type' => 'text'],
            ['name' => 'Units', 'type' => 'number'],
        ]);
        $brandCol = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Brand')->first();
        $unitsCol = CustomTableColumn::query()->where('table_key', $table->key)->where('name', 'Units')->first();

        $path = tempnam(sys_get_temp_dir(), 'dyn').'.csv';
        file_put_contents($path, "name,brand,units\nMRI,GE,4\nCT,Philips,2\n");

        $mapping = [
            'name' => 'A',
            $brandCol->columnKey() => 'B',
            $unitsCol->columnKey() => 'C',
        ];

        $service = app(DynamicTableImportService::class);
        $batch = $service->import($path, $table->key, 'CSV', $mapping, 1, 2);

        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(2, DynamicRow::query()->where('table_key', $table->key)->count());

        // Re-import the same file -> upsert, not duplicate.
        $service->import($path, $table->key, 'CSV', $mapping, 1, 2);
        $this->assertSame(2, DynamicRow::query()->where('table_key', $table->key)->count());

        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $brandCol->id,
            'value_text' => 'GE',
        ]);
        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $unitsCol->id,
            'value_number' => 4,
        ]);

        @unlink($path);
    }
}
