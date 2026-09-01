<?php

namespace Tests\Feature;

use App\Livewire\ServiceRequestTable;
use App\Models\RecordEditLog;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_inline_edits_are_logged_with_old_and_new_values(): void
    {
        $request = ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-LOG-1',
            'service_request_number' => 'SR-LOG-1',
            'customer_name' => 'Example Hospital',
            'ticket_status' => 'OPEN',
        ]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(ServiceRequestTable::class)
            ->call('updateField', $request->id, 'ticket_status', 'In-Progress');

        $log = RecordEditLog::query()->firstOrFail();
        $this->assertSame('updated', $log->action);
        $this->assertSame('ticket_status', $log->field);
        $this->assertSame('OPEN', $log->old_value);
        $this->assertSame('In-Progress', $log->new_value);
        $this->assertSame('service-requests', $log->table_key);
        $this->assertNotNull($log->user_id);
    }

    public function test_reads_do_not_create_log_entries(): void
    {
        ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-LOG-2',
            'service_request_number' => 'SR-LOG-2',
        ]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(ServiceRequestTable::class)
            ->call('sortBy', 'customer_name');

        $this->assertSame(0, RecordEditLog::query()->count());
    }

    public function test_status_filter_is_url_bound_for_drill_down(): void
    {
        ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-OPEN-1',
            'service_request_number' => 'SR-OPEN-1',
            'customer_name' => 'Open Hospital',
            'ticket_status' => 'OPEN',
        ]);
        ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-DONE-1',
            'service_request_number' => 'SR-DONE-1',
            'customer_name' => 'Done Hospital',
            'ticket_status' => 'Completed',
        ]);

        Livewire::actingAs(User::factory()->president()->create())
            ->withQueryParams(['status' => 'OPEN'])
            ->test(ServiceRequestTable::class)
            ->assertSee('Open Hospital')
            ->assertDontSee('Done Hospital');
    }
}
