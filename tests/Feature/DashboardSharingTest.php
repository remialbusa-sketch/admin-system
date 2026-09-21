<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\DashboardsIndex;
use App\Models\Dashboard as DashboardModel;
use App\Models\DashboardShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardSharingTest extends TestCase
{
    use RefreshDatabase;

    private function dashboardFor(User $owner, array $attributes = []): DashboardModel
    {
        $dashboard = DashboardModel::create(array_merge([
            'owner_id' => $owner->id,
            'name' => 'Ops board',
            'layout' => ['version' => 1, 'widgets' => []],
        ], $attributes));

        $dashboard->sources()->create([
            'table_key' => 'installed-products',
            'alias' => 'pdb',
            'position' => 0,
        ]);

        return $dashboard;
    }

    public function test_owner_can_create_a_dashboard_from_the_index(): void
    {
        $owner = User::factory()->superadmin()->create();

        Livewire::actingAs($owner)
            ->test(DashboardsIndex::class)
            ->set('newName', 'Visayas ops')
            ->call('createDashboard')
            ->assertHasNoErrors();

        $dashboard = DashboardModel::query()->where('name', 'Visayas ops')->first();

        $this->assertNotNull($dashboard);
        $this->assertSame($owner->id, $dashboard->owner_id);
        $this->assertDatabaseHas('dashboard_sources', [
            'dashboard_id' => $dashboard->id,
            'table_key' => 'installed-products',
            'alias' => 'pdb',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboards.show', $dashboard))
            ->assertOk()
            ->assertSee('Visayas ops');
    }

    public function test_a_dashboard_is_private_until_shared(): void
    {
        $owner = User::factory()->superadmin()->create();
        $other = User::factory()->president()->create();
        $dashboard = $this->dashboardFor($owner);

        $this->actingAs($other)
            ->get(route('dashboards.show', $dashboard))
            ->assertForbidden();

        // View-only share: can open, cannot customize.
        DashboardShare::create([
            'dashboard_id' => $dashboard->id,
            'user_id' => $other->id,
            'permission' => 'view',
            'shared_by' => $owner->id,
        ]);

        $this->actingAs($other)
            ->get(route('dashboards.show', $dashboard))
            ->assertOk();

        Livewire::actingAs($other)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->assertForbidden();
    }

    public function test_shared_editor_can_save_the_layout_and_viewer_cannot(): void
    {
        $owner = User::factory()->superadmin()->create();
        $editor = User::factory()->president()->create();
        $dashboard = $this->dashboardFor($owner);

        DashboardShare::create([
            'dashboard_id' => $dashboard->id,
            'user_id' => $editor->id,
            'permission' => 'edit',
            'shared_by' => $owner->id,
        ]);

        Livewire::actingAs($editor)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->assertHasNoErrors()
            ->call('saveLayout')
            ->assertHasNoErrors();

        $this->assertIsArray($dashboard->fresh()->layout);

        // Downgrade to view: mutations fail closed even with a stale snapshot.
        DashboardShare::query()
            ->where('dashboard_id', $dashboard->id)
            ->where('user_id', $editor->id)
            ->update(['permission' => 'view']);

        Livewire::actingAs($editor)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->assertForbidden();
    }

    public function test_owner_can_connect_and_remove_data_sources(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->dashboardFor($owner);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->set('sourceTableKey', 'service-requests')
            ->call('connectSource')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dashboard_sources', [
            'dashboard_id' => $dashboard->id,
            'table_key' => 'service-requests',
            'alias' => 'service_requests',
        ]);

        // Connecting the same table again gets a unique alias.
        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->set('sourceTableKey', 'service-requests')
            ->call('connectSource')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dashboard_sources', [
            'dashboard_id' => $dashboard->id,
            'alias' => 'service_requests_2',
        ]);

        $source = $dashboard->sources()->where('alias', 'service_requests')->first();

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('removeSource', $source->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('dashboard_sources', ['id' => $source->id]);
    }

    public function test_system_dashboards_are_viewable_by_everyone_but_not_editable(): void
    {
        $admin = User::factory()->superadmin()->create();
        $viewer = User::factory()->president()->create();

        $dashboard = DashboardModel::create([
            'owner_id' => null,
            'name' => 'Company overview',
            'is_system' => true,
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        $this->actingAs($viewer)
            ->get(route('dashboards.show', $dashboard))
            ->assertOk();

        Livewire::actingAs($viewer)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->assertForbidden();
    }

    public function test_share_grants_are_managed_by_the_owner_only(): void
    {
        $owner = User::factory()->superadmin()->create();
        $editor = User::factory()->president()->create();
        $target = User::factory()->vpOperations()->create();
        $dashboard = $this->dashboardFor($owner);

        DashboardShare::create([
            'dashboard_id' => $dashboard->id,
            'user_id' => $editor->id,
            'permission' => 'edit',
            'shared_by' => $owner->id,
        ]);

        Livewire::actingAs($editor)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->set('shareUserId', (string) $target->id)
            ->set('sharePermission', 'edit')
            ->call('shareDashboard')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dashboard_shares', [
            'dashboard_id' => $dashboard->id,
            'user_id' => $target->id,
            'permission' => 'edit',
        ]);

        // A viewer cannot share.
        DashboardShare::query()
            ->where('dashboard_id', $dashboard->id)
            ->where('user_id', $editor->id)
            ->update(['permission' => 'view']);

        Livewire::actingAs($editor)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->set('shareUserId', (string) $target->id)
            ->call('shareDashboard')
            ->assertForbidden();
    }
}
