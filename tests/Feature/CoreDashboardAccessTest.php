<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\DashboardsIndex;
use App\Livewire\Sidebar;
use App\Livewire\TechnicalServiceAnalysis;
use App\Livewire\TspAnalytics;
use App\Models\Dashboard as DashboardModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Core dashboards (Home, TSA, TSP) are shared with EVERYONE: every role can
 * view the three pages and their one shared layout, while only superadmin/admin
 * accounts may edit that layout. Opening a system row lands on the canonical
 * page (the old per-user template copy is gone).
 */
class CoreDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    /** President role with the default viewer permission. */
    private function viewer(): User
    {
        return User::factory()->president()->create();
    }

    private function coreRow(string $name): DashboardModel
    {
        return DashboardModel::query()
            ->where('is_system', true)
            ->where('name', $name)
            ->firstOrFail();
    }

    public function test_every_role_can_view_the_three_core_pages(): void
    {
        $user = $this->viewer();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Installed products')
            ->assertDontSee('No dashboard yet');

        $this->actingAs($user)
            ->get(route('technical-service-analysis'))
            ->assertOk()
            ->assertSee('Technical reports');

        $this->actingAs($user)
            ->get(route('tsp-analytics'))
            ->assertOk()
            ->assertSee('Reports (filtered)');
    }

    public function test_editing_core_dashboards_requires_admin_permission(): void
    {
        $viewer = $this->viewer();
        $editor = User::factory()->nationalManager()->state(['permission' => 'editor'])->create();
        $admin = User::factory()->nationalManager()->state(['permission' => 'admin'])->create();

        Livewire::actingAs($viewer)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->assertForbidden();

        // Editors are excluded from core dashboards by design.
        Livewire::actingAs($editor)
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->assertForbidden();

        Livewire::actingAs($editor)
            ->test(TspAnalytics::class)
            ->call('toggleCustomizingFor', 'tsp')
            ->assertForbidden();

        Livewire::actingAs($admin)
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->assertHasNoErrors();
    }

    public function test_home_layout_is_one_shared_layout_for_everyone(): void
    {
        $admin = User::factory()->nationalManager()->state(['permission' => 'admin'])->create();

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'home-installed')
            ->set('settingsProps.label', 'Shared home marker')
            ->call('applyWidgetSettings')
            ->call('saveLayout', [])
            ->assertHasNoErrors();

        $this->actingAs($this->viewer())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Shared home marker');
    }

    public function test_tsa_and_tsp_layouts_are_one_shared_layout_for_everyone(): void
    {
        $admin = User::factory()->nationalManager()->state(['permission' => 'admin'])->create();

        Livewire::actingAs($admin)
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->call('editWidgetFor', 'tsa', 'tsa-reports')
            ->set('widgetGrids.tsa.settingsProps.label', 'Shared tsa marker')
            ->call('applyWidgetSettingsFor', 'tsa')
            ->call('saveWidgetGrid', [], 'tsa')
            ->assertHasNoErrors();

        $this->actingAs($this->viewer())
            ->get(route('technical-service-analysis'))
            ->assertOk()
            ->assertSee('Shared tsa marker');

        Livewire::actingAs($admin)
            ->test(TspAnalytics::class)
            ->call('toggleCustomizingFor', 'tsp')
            ->call('editWidgetFor', 'tsp', 'tsp-filtered')
            ->set('widgetGrids.tsp.settingsProps.label', 'Shared tsp marker')
            ->call('applyWidgetSettingsFor', 'tsp')
            ->call('saveWidgetGrid', [], 'tsp')
            ->assertHasNoErrors();

        $this->actingAs($this->viewer())
            ->get(route('tsp-analytics'))
            ->assertOk()
            ->assertSee('Shared tsp marker');
    }

    public function test_opening_a_core_dashboard_row_lands_on_the_shared_page(): void
    {
        $user = $this->viewer();

        $this->actingAs($user)
            ->get(route('dashboards.show', $this->coreRow('Home')))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($user)
            ->get(route('dashboards.show', $this->coreRow('Technical Service Analysis')))
            ->assertRedirect(route('technical-service-analysis'));

        $this->actingAs($user)
            ->get(route('dashboards.show', $this->coreRow('TSP Analytics')))
            ->assertRedirect(route('tsp-analytics'));

        // The old template-copied flow is gone: no personal copies appear.
        $this->assertSame(0, DashboardModel::query()->where('owner_id', $user->id)->count());
    }

    public function test_core_dashboards_stay_out_of_the_sidebar_dashboards_list(): void
    {
        $user = $this->viewer();

        DashboardModel::create([
            'owner_id' => $user->id,
            'name' => 'My personal board',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        // Core rows live under their dedicated Analytics links — they must
        // never appear as Tpl entries inside the Dashboards sub-list, nor
        // inflate its badge. Personal dashboards still list normally.
        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->assertDontSee('Tpl')
            ->assertSee('My personal board')
            ->assertViewHas('dashboards', function ($dashboards): bool {
                return $dashboards->count() === 1
                    && $dashboards->every(fn (DashboardModel $dashboard): bool => ! $dashboard->is_system);
            });

        // The index page still shows the System dashboards section (by design).
        $this->actingAs($user)
            ->get(route('dashboards.index'))
            ->assertOk()
            ->assertSee('System dashboards')
            ->assertDontSee('All dashboards (superadmin)');
    }

    public function test_core_dashboard_names_are_fixed(): void
    {
        $superadmin = User::factory()->superadmin()->create();

        Livewire::actingAs($superadmin)
            ->test(Dashboard::class)
            ->call('startHeaderEdit')
            ->set('editingName', 'Renamed home')
            ->call('saveHeader')
            ->assertForbidden();

        $this->assertSame('Home', $this->coreRow('Home')->fresh()->name);

        Livewire::actingAs($superadmin)
            ->test(DashboardsIndex::class)
            ->call('startRename', $this->coreRow('TSP Analytics')->id, 'Renamed TSP')
            ->call('rename')
            ->assertForbidden();

        $this->assertSame('TSP Analytics', $this->coreRow('TSP Analytics')->fresh()->name);
    }
}
