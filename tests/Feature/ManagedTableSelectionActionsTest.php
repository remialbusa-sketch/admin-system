<?php

namespace Tests\Feature;

use App\Livewire\ServiceRequestTable;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManagedTableSelectionActionsTest extends TestCase
{
    use RefreshDatabase;

    private function createServiceRequest(array $attributes = []): ServiceRequest
    {
        return ServiceRequest::create(array_merge([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-'.uniqid(),
            'service_request_code' => 'SR-'.random_int(10000, 99999),
            'customer_name' => 'Selection Test Customer',
        ], $attributes));
    }

    public function test_superadmin_can_duplicate_a_record_and_read_only_users_cannot(): void
    {
        $record = $this->createServiceRequest();

        Livewire::actingAs(User::factory()->president()->create())
            ->test(ServiceRequestTable::class)
            ->call('duplicateSelected', [$record->id])
            ->assertForbidden();

        $this->assertDatabaseCount('service_requests', 1);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(ServiceRequestTable::class)
            ->call('duplicateSelected', [$record->id])
            ->assertHasNoErrors();

        $this->assertDatabaseCount('service_requests', 2);

        $copy = ServiceRequest::query()
            ->where('id', '!=', $record->id)
            ->first();

        $this->assertNotNull($copy);
        $this->assertSame($record->service_request_code, $copy->service_request_code);
        $this->assertSame($record->customer_name, $copy->customer_name);
        // The copy must carry a regenerated source identity so re-imports
        // never match (or clobber) it.
        $this->assertNotSame($record->source_record_id, $copy->source_record_id);
        $this->assertStringStartsWith($record->source_record_id.'-copy-', $copy->source_record_id);
        $this->assertNull($copy->archived_at);
    }

    public function test_duplicate_copies_custom_column_values(): void
    {
        $record = $this->createServiceRequest();

        $column = CustomTableColumn::create([
            'table_key' => 'service-requests',
            'name' => 'Follow-up Notes',
            'type' => 'text',
        ]);

        CustomTableColumnValue::create([
            'custom_column_id' => $column->id,
            'row_id' => $record->id,
            'value' => ['text' => 'Call the lab manager'],
            'value_text' => 'Call the lab manager',
        ]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(ServiceRequestTable::class)
            ->call('duplicateSelected', [$record->id])
            ->assertHasNoErrors();

        $copyId = ServiceRequest::query()->where('id', '!=', $record->id)->value('id');

        $this->assertNotNull($copyId);
        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $column->id,
            'row_id' => $copyId,
            'value_text' => 'Call the lab manager',
        ]);
    }

    public function test_archive_hides_records_until_restored_and_gates_editing(): void
    {
        $record = $this->createServiceRequest(['customer_name' => 'Archive Me']);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(ServiceRequestTable::class)
            ->call('archiveSelected', [$record->id])
            ->assertForbidden();

        $this->assertDatabaseHas('service_requests', [
            'id' => $record->id,
            'archived_at' => null,
        ]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(ServiceRequestTable::class)
            ->call('archiveSelected', [$record->id])
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('service_requests', [
            'id' => $record->id,
            'archived_at' => null,
        ]);

        // Archived records leave the default listing...
        Livewire::actingAs(User::factory()->president()->create())
            ->test(ServiceRequestTable::class)
            ->assertDontSee('Archive Me')
            // ...and appear in the archive view.
            ->call('toggleShowArchived')
            ->assertSee('Archive Me');

        // Restore puts the record back into the active listing.
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(ServiceRequestTable::class)
            ->call('toggleShowArchived')
            ->call('restoreSelected', [$record->id])
            ->assertHasNoErrors();

        $this->assertDatabaseHas('service_requests', [
            'id' => $record->id,
            'archived_at' => null,
        ]);
    }

    public function test_archive_view_hides_active_records(): void
    {
        $active = $this->createServiceRequest(['customer_name' => 'Still Active']);
        $archived = $this->createServiceRequest(['customer_name' => 'Long Gone']);

        // archived_at is intentionally not fillable; archive actions go
        // through guarded query updates.
        ServiceRequest::query()->whereKey($archived->id)->update(['archived_at' => now()]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(ServiceRequestTable::class)
            ->call('toggleShowArchived')
            ->assertSee('Long Gone')
            ->assertDontSee('Still Active');
    }

    public function test_bulk_delete_stays_gated_to_superadmin(): void
    {
        $record = $this->createServiceRequest();

        Livewire::actingAs(User::factory()->president()->create())
            ->test(ServiceRequestTable::class)
            ->call('deleteSelected', [$record->id])
            ->assertForbidden();

        $this->assertDatabaseHas('service_requests', ['id' => $record->id]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(ServiceRequestTable::class)
            ->call('deleteSelected', [$record->id])
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('service_requests', ['id' => $record->id]);
    }
}
