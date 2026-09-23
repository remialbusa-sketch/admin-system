<?php

namespace Tests\Feature;

use App\Enums\UserPermission;
use App\Livewire\TablesList;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\ImportBatch;
use App\Models\MondaySyncSetting;
use App\Models\RecordEditLog;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImportUndoTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    private function dynamicTable(User $owner): DynamicTable
    {
        $table = DynamicTable::create(['key' => 'undo_table', 'name' => 'Undo Table', 'created_by' => $owner->id]);

        CustomTableColumn::create([
            'table_key' => $table->key,
            'name' => 'Note',
            'type' => 'text',
            'position' => 0,
            'created_by' => $owner->id,
        ]);

        return $table;
    }

    private function batch(User $runner, array $overrides = []): ImportBatch
    {
        return ImportBatch::create([
            'source_system' => 'dynamic',
            'source_name' => 'wrong.xlsx',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(30),
            'run_by' => $runner->id,
            ...$overrides,
        ]);
    }

    private function dynamicRow(DynamicTable $table, ImportBatch $batch, string $recordId, string $createdAt): DynamicRow
    {
        // import_batch_id is deliberately NOT fillable (production stamps it
        // via direct assignment after upserts) — mirror that here.
        $row = DynamicRow::create([
            'table_key' => $table->key,
            'name' => 'Row '.$recordId,
            'source_system' => 'dynamic:'.$table->key,
            'source_record_id' => $recordId,
        ]);
        $row->import_batch_id = $batch->id;
        $row->created_at = $createdAt;
        $row->save();

        $column = CustomTableColumn::query()->where('table_key', $table->key)->firstOrFail();

        CustomTableColumnValue::create([
            'custom_column_id' => $column->id,
            'row_id' => $row->id,
            'value' => ['text' => 'v-'.$recordId],
            'value_text' => 'v-'.$recordId,
        ]);

        return $row;
    }

    public function test_undo_deletes_created_rows_and_reports_updated_ones(): void
    {
        $owner = $this->superadmin();
        $table = $this->dynamicTable($owner);
        $batch = $this->batch($owner);

        $created = $this->dynamicRow($table, $batch, 'new-1', now()->subMinutes(45)->toDateTimeString());
        $updated = $this->dynamicRow($table, $batch, 'old-1', now()->subDays(2)->toDateTimeString());

        $other = $this->batch($owner, ['started_at' => now()->subDays(3), 'completed_at' => now()->subDays(3)->addMinutes(5)]);
        $untouched = $this->dynamicRow($table, $other, 'other-1', now()->subDays(3)->toDateTimeString());

        $coreRow = ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-undo-core',
            'group_status' => 'Open',
            'region' => 'Region 6',
            'import_batch_id' => $batch->id,
        ]);
        $coreRow->created_at = now()->subMinutes(40)->toDateTimeString();
        $coreRow->save();

        Livewire::actingAs($owner)
            ->test(TablesList::class)
            ->call('undoImport', $batch->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('dynamic_rows', ['id' => $created->id]);
        $this->assertDatabaseMissing('table_custom_column_values', ['row_id' => $created->id]);
        $this->assertDatabaseHas('dynamic_rows', ['id' => $updated->id]);
        $this->assertDatabaseHas('dynamic_rows', ['id' => $untouched->id]);
        $this->assertDatabaseMissing('service_requests', ['id' => $coreRow->id]);
        $this->assertSame('undone', $batch->fresh()->status);
        // Structure survives the undo.
        $this->assertDatabaseHas('dynamic_tables', ['key' => $table->key]);
    }

    public function test_undo_rejects_reruns_and_running_batches(): void
    {
        $owner = $this->superadmin();
        $table = $this->dynamicTable($owner);

        $done = $this->batch($owner, ['status' => 'undone']);
        $running = $this->batch($owner, ['status' => 'processing', 'completed_at' => null]);

        $component = Livewire::actingAs($owner)->test(TablesList::class);

        $component->call('undoImport', $done->id)->assertSee('already undone');

        $component->call('undoImport', $running->id)->assertSee('still processing');

        $this->assertDatabaseHas('dynamic_tables', ['key' => $table->key]);
    }

    public function test_undo_gates_non_owners_and_allows_the_runner(): void
    {
        $owner = $this->superadmin();
        $table = $this->dynamicTable($owner);
        $batch = $this->batch($owner);
        $this->dynamicRow($table, $batch, 'new-1', now()->subMinutes(45)->toDateTimeString());

        $viewer = User::factory()->president()->create();

        Livewire::actingAs($viewer)
            ->test(TablesList::class)
            ->call('undoImport', $batch->id)
            ->assertForbidden();

        $editor = User::factory()->serviceCoordinator()->create(['permission' => UserPermission::Editor]);
        $runnerBatch = $this->batch($editor);

        Livewire::actingAs($editor)
            ->test(TablesList::class)
            ->call('undoImport', $runnerBatch->id)
            ->assertHasNoErrors();

        $this->assertSame('undone', $runnerBatch->fresh()->status);
    }

    public function test_undo_is_blocked_by_an_enabled_monday_sync(): void
    {
        $owner = $this->superadmin();
        $table = $this->dynamicTable($owner);
        $batch = $this->batch($owner);
        $row = $this->dynamicRow($table, $batch, 'new-1', now()->subMinutes(45)->toDateTimeString());

        MondaySyncSetting::firstOrCreate(['domain' => $table->key])->update(['enabled' => true]);

        Livewire::actingAs($owner)
            ->test(TablesList::class)
            ->call('undoImport', $batch->id)
            ->assertHasNoErrors()
            ->assertSee('Pause the monday.com sync');
        $this->assertDatabaseHas('dynamic_rows', ['id' => $row->id]);
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_empty_dynamic_table_keeps_structure_but_drops_rows_and_values(): void
    {
        $owner = $this->superadmin();
        $table = $this->dynamicTable($owner);
        $batch = $this->batch($owner);
        $row = $this->dynamicRow($table, $batch, 'r1', now()->subMinutes(45)->toDateTimeString());

        Livewire::actingAs($owner)
            ->test(TablesList::class)
            ->call('emptyTable', $table->key)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('dynamic_rows', ['id' => $row->id]);
        $this->assertDatabaseMissing('table_custom_column_values', ['row_id' => $row->id]);
        $this->assertDatabaseHas('dynamic_tables', ['key' => $table->key]);
        $this->assertDatabaseHas('table_custom_columns', ['table_key' => $table->key]);
    }

    public function test_empty_core_table_purges_records_for_superadmin_only(): void
    {
        $owner = $this->superadmin();

        $request = ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-undo-1',
            'group_status' => 'Open',
            'region' => 'Region 6',
        ]);

        Livewire::actingAs($owner)
            ->test(TablesList::class)
            ->call('emptyTable', 'service-requests')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('service_requests', ['id' => $request->id]);

        $request2 = ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-undo-2',
            'group_status' => 'Open',
            'region' => 'Region 6',
        ]);

        $viewer = User::factory()->president()->create();

        Livewire::actingAs($viewer)
            ->test(TablesList::class)
            ->call('emptyTable', 'service-requests')
            ->assertForbidden();

        Livewire::actingAs($owner)
            ->test(TablesList::class)
            ->call('emptyTable', 'no-such-table')
            ->assertNotFound();

        $this->assertDatabaseHas('service_requests', ['id' => $request2->id]);
    }

    public function test_clear_history_removes_logs_but_keeps_table_data(): void
    {
        $owner = $this->superadmin();

        $batch = $this->batch($owner);

        RecordEditLog::create([
            'user_id' => $owner->id,
            'table_key' => 'service-requests',
            'row_id' => 1,
            'action' => 'update',
            'field' => 'group_status',
            'old_value' => 'Open',
            'new_value' => 'Closed',
        ]);

        Livewire::actingAs($owner)
            ->test(TablesList::class)
            ->call('clearImportHistory')
            ->assertHasNoErrors()
            ->assertSee('Table data untouched')
            ->call('clearEditHistory')
            ->assertHasNoErrors()
            ->assertSee('Table data untouched');

        $this->assertDatabaseMissing('import_batches', ['id' => $batch->id]);
        $this->assertDatabaseMissing('record_edit_logs', ['table_key' => 'service-requests']);

        $viewer = User::factory()->president()->create();

        Livewire::actingAs($viewer)
            ->test(TablesList::class)
            ->call('clearImportHistory')
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(TablesList::class)
            ->call('clearEditHistory')
            ->assertForbidden();
    }
}
