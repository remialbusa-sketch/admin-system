<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\DashboardsIndex;
use App\Livewire\Sidebar;
use App\Models\Dashboard as DashboardModel;
use App\Models\DashboardAuditLog;
use App\Models\DashboardShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardSuperadminTest extends TestCase
{
    use RefreshDatabase;

    private function dashboardFor(User $owner, string $name = 'Private board'): DashboardModel
    {
        $dashboard = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => $name,
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        $dashboard->sources()->create(['table_key' => 'installed-products', 'alias' => 'pdb', 'position' => 0]);

        return $dashboard;
    }

    public function test_superadmin_can_view_and_edit_another_users_private_dashboard(): void
    {
        $owner = User::factory()->president()->create();
        $superadmin = User::factory()->superadmin()->create();
        $dashboard = $this->dashboardFor($owner);

        $this->actingAs($superadmin)
            ->get(route('dashboards.show', $dashboard))
            ->assertOk()
            ->assertSee('Private board');

        Livewire::actingAs($superadmin)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->assertHasNoErrors()
            ->call('saveLayout')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dashboard_audit_logs', [
            'dashboard_id' => $dashboard->id,
            'user_id' => $superadmin->id,
            'action' => 'layout_saved',
        ]);

        // The owner still keeps access.
        $this->actingAs($owner)
            ->get(route('dashboards.show', $dashboard))
            ->assertOk();
    }

    public function test_a_regular_user_still_cannot_open_a_private_dashboard(): void
    {
        $owner = User::factory()->president()->create();
        $other = User::factory()->vpOperations()->create();
        $dashboard = $this->dashboardFor($owner);

        // Mount-level: not viewable at all.
        $this->actingAs($other)
            ->get(route('dashboards.show', $dashboard))
            ->assertForbidden();

        // Method-level: a view-only share can open the page but never edit.
        DashboardShare::create([
            'dashboard_id' => $dashboard->id,
            'user_id' => $other->id,
            'permission' => 'view',
            'shared_by' => $owner->id,
        ]);

        Livewire::actingAs($other)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->assertForbidden();
    }

    public function test_superadmin_can_rename_archive_and_restore_any_dashboard(): void
    {
        $owner = User::factory()->president()->create();
        $superadmin = User::factory()->superadmin()->create();
        $dashboard = $this->dashboardFor($owner);

        Livewire::actingAs($superadmin)
            ->test(DashboardsIndex::class)
            ->call('startRename', $dashboard->id, 'Renamed by admin')
            ->call('rename')
            ->assertHasNoErrors();

        $this->assertSame('Renamed by admin', $dashboard->fresh()->name);

        Livewire::actingAs($superadmin)
            ->test(DashboardsIndex::class)
            ->call('deleteDashboard', $dashboard->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('dashboards', ['id' => $dashboard->id]);

        Livewire::actingAs($superadmin)
            ->test(DashboardsIndex::class)
            ->call('restoreDashboard', $dashboard->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dashboards', ['id' => $dashboard->id, 'deleted_at' => null]);
    }

    public function test_superadmin_can_archive_and_restore_a_system_template(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $system = DashboardModel::query()->where('is_system', true)->where('name', 'TSP Analytics')->firstOrFail();

        Livewire::actingAs($superadmin)
            ->test(DashboardsIndex::class)
            ->call('deleteDashboard', $system->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('dashboards', ['id' => $system->id]);

        Livewire::actingAs($superadmin)
            ->test(DashboardsIndex::class)
            ->call('restoreDashboard', $system->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dashboards', ['id' => $system->id, 'deleted_at' => null]);
    }

    public function test_superadmin_can_permanently_delete_an_archived_dashboard_and_the_audit_survives(): void
    {
        $owner = User::factory()->president()->create();
        $superadmin = User::factory()->superadmin()->create();
        $dashboard = $this->dashboardFor($owner, 'Doomed board');

        Livewire::actingAs($superadmin)
            ->test(DashboardsIndex::class)
            ->call('deleteDashboard', $dashboard->id);

        Livewire::actingAs($superadmin)
            ->test(DashboardsIndex::class)
            ->call('forceDeleteDashboard', $dashboard->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('dashboards', ['id' => $dashboard->id]);
        $this->assertDatabaseMissing('dashboard_sources', ['dashboard_id' => $dashboard->id]);

        // The audit trail keeps the history with a nulled dashboard reference.
        $this->assertDatabaseHas('dashboard_audit_logs', [
            'action' => 'force_deleted',
            'dashboard_id' => null,
        ]);
    }

    public function test_all_dashboards_tab_is_superadmin_only(): void
    {
        $owner = User::factory()->president()->create();
        $superadmin = User::factory()->superadmin()->create();
        $this->dashboardFor($owner, 'Confidential ops board');

        $this->actingAs($superadmin)
            ->get(route('dashboards.index'))
            ->assertOk()
            ->assertSee('All dashboards (superadmin)')
            ->assertSee('Confidential ops board');

        $this->actingAs($owner)
            ->get(route('dashboards.index'))
            ->assertOk()
            ->assertDontSee('All dashboards (superadmin)');
    }

    public function test_reads_do_not_write_audit_entries(): void
    {
        $owner = User::factory()->president()->create();
        $superadmin = User::factory()->superadmin()->create();
        $dashboard = $this->dashboardFor($owner);

        $this->actingAs($superadmin)->get(route('dashboards.show', $dashboard))->assertOk();
        $this->actingAs($superadmin)->get(route('dashboards.index'))->assertOk();

        $this->assertSame(0, DashboardAuditLog::query()->count());
    }

    public function test_sidebar_stays_scoped_for_superadmins(): void
    {
        $owner = User::factory()->president()->create();
        $superadmin = User::factory()->superadmin()->create();
        $this->dashboardFor($owner, 'Not in sidebar');

        // The All tab is the deliberate admin view; the everyday sidebar must
        // stay scoped to owned + shared dashboards.
        Livewire::actingAs($superadmin)
            ->test(Sidebar::class)
            ->assertDontSee('Not in sidebar');
    }
}
