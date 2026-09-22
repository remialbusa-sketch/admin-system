<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Dashboard as DashboardModel;
use App\Models\DashboardShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardHeaderEditTest extends TestCase
{
    use RefreshDatabase;

    private function dashboardFor(User $owner): DashboardModel
    {
        $dashboard = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'Ops board',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        $dashboard->sources()->create([
            'table_key' => 'installed-products',
            'alias' => 'pdb',
            'position' => 0,
        ]);

        return $dashboard;
    }

    public function test_owner_can_rename_the_dashboard_and_edit_its_description(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->dashboardFor($owner);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('startHeaderEdit')
            ->assertSet('editingHeader', true)
            ->assertSet('editingName', 'Ops board')
            ->set('editingName', 'Visayas ops')
            ->set('editingDescription', 'Weekly operations review')
            ->call('saveHeader')
            ->assertHasNoErrors()
            ->assertSet('editingHeader', false);

        $dashboard->refresh();

        $this->assertSame('Visayas ops', $dashboard->name);
        $this->assertSame('Weekly operations review', $dashboard->description);
        $this->assertDatabaseHas('dashboard_audit_logs', [
            'dashboard_id' => $dashboard->id,
            'user_id' => $owner->id,
            'action' => 'renamed',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboards.show', $dashboard))
            ->assertOk()
            ->assertSee('Visayas ops')
            ->assertSee('Weekly operations review');
    }

    public function test_cancelling_an_edit_keeps_the_stored_header(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->dashboardFor($owner);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('startHeaderEdit')
            ->set('editingName', 'Discarded name')
            ->call('cancelHeaderEdit')
            ->assertSet('editingHeader', false)
            ->assertSet('editingName', '');

        $this->assertSame('Ops board', $dashboard->refresh()->name);
        $this->assertDatabaseMissing('dashboard_audit_logs', [
            'dashboard_id' => $dashboard->id,
            'action' => 'renamed',
        ]);
    }

    public function test_header_edit_validates_name_and_description_length(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->dashboardFor($owner);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('startHeaderEdit')
            ->set('editingName', str_repeat('a', 101))
            ->set('editingDescription', str_repeat('b', 501))
            ->call('saveHeader')
            ->assertHasErrors(['editingName' => 'max', 'editingDescription' => 'max']);

        $this->assertSame('Ops board', $dashboard->refresh()->name);
    }

    public function test_a_view_only_share_cannot_edit_the_header(): void
    {
        $owner = User::factory()->president()->create();
        $viewer = User::factory()->vpOperations()->create();
        $dashboard = $this->dashboardFor($owner);

        DashboardShare::create([
            'dashboard_id' => $dashboard->id,
            'user_id' => $viewer->id,
            'permission' => 'view',
            'shared_by' => $owner->id,
        ]);

        Livewire::actingAs($viewer)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('startHeaderEdit')
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('saveHeader')
            ->assertForbidden();
    }
}
