<?php

namespace Tests\Feature;

use App\Enums\UserPermission;
use App\Livewire\ServiceRequestTable;
use App\Models\RecordEditLog;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Permission levels gate what an account may DO:
 *   viewer = read-only; editor = edit records; admin/superadmin = + imports.
 * Roles are separate from permissions.
 */
class PermissionLevelTest extends TestCase
{
    use RefreshDatabase;

    private function request(): ServiceRequest
    {
        return ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-PERM-1',
            'service_request_number' => 'SR-PERM-1',
            'customer_name' => 'Example Hospital',
            'ticket_status' => 'OPEN',
        ]);
    }

    public function test_viewer_cannot_edit_records(): void
    {
        $request = $this->request();

        Livewire::actingAs(User::factory()->serviceCoordinator()->create())
            ->test(ServiceRequestTable::class)
            ->call('updateField', $request->id, 'ticket_status', 'In-Progress')
            ->assertStatus(403);

        $this->assertSame('OPEN', $request->fresh()->ticket_status);
        $this->assertSame(0, RecordEditLog::query()->count());
    }

    public function test_editor_can_edit_records(): void
    {
        $request = $this->request();

        Livewire::actingAs(User::factory()->serviceCoordinator()->create(['permission' => UserPermission::Editor]))
            ->test(ServiceRequestTable::class)
            ->call('updateField', $request->id, 'ticket_status', 'In-Progress')
            ->assertHasNoErrors();

        $this->assertSame('In-Progress', $request->fresh()->ticket_status);
        $this->assertSame(1, RecordEditLog::query()->count());
    }

    public function test_editor_cannot_import(): void
    {
        Livewire::actingAs(User::factory()->assistantCoordinator()->create(['permission' => UserPermission::Editor]))
            ->test(ServiceRequestTable::class)
            ->call('analyzeStreamedImport', str_repeat('a', 32), 'file.xlsx')
            ->assertStatus(403);
    }

    public function test_admin_permission_can_import(): void
    {
        // An admin-level (non-superadmin) account passes the import gate;
        // reaching the analyzer implies canImport was true.
        $user = User::factory()->serviceCoordinator()->create(['permission' => UserPermission::Admin]);

        $this->assertTrue($user->canImport());
        $this->assertTrue($user->canEditRecords());
    }

    public function test_superadmin_role_always_admin_level(): void
    {
        $superadmin = User::factory()->superadmin()->create();

        $this->assertTrue($superadmin->canImport());
        $this->assertTrue($superadmin->canEditRecords());
    }

    public function test_viewer_is_read_only_for_import_and_edit(): void
    {
        $viewer = User::factory()->assistant()->create();

        $this->assertFalse($viewer->canEditRecords());
        $this->assertFalse($viewer->canImport());
    }
}
