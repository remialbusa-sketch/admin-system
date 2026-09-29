<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Dashboard as DashboardModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardHomeWidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_renders_headline_widgets_by_default(): void
    {
        $owner = User::factory()->superadmin()->create();

        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->assertViewHas('grid', function (array $grid): bool {
                $widgets = $grid['widgets'];

                // 8 headline widgets plus the full shipped operations grid.
                if (count($widgets) !== 18) {
                    return false;
                }

                foreach ($widgets as $widget) {
                    if (($widget['view'] ?? null) === 'components.dashboard.widgets.error') {
                        return false;
                    }
                }

                $byId = collect($widgets)->keyBy('id');

                return ($byId['home-installed']['props']['metric'] ?? null) === 'installed'
                    && ($byId['home-annual']['props']['formula'] ?? null) === 'round(annual_bu_charges / 1000000, 1)'
                    && ($byId['home-missing-pms']['props']['metric'] ?? null) === 'missing_pms'
                    && isset($byId['kpi-activation'], $byId['table-regions']);
            })
            // No hardcoded curated markup remains.
            ->assertDontSee('Headline metrics');
    }

    public function test_home_headline_widget_is_fully_customizable(): void
    {
        $owner = User::factory()->superadmin()->create();

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->call('toggleCustomizing');

        $component
            ->call('editWidget', 'home-installed')
            ->set('settingsProps.label', 'My installs')
            ->call('applyWidgetSettings')
            ->assertSet('settingsError', null);

        $this->assertSame(
            'My installs',
            collect($component->get('draftLayout')['widgets'])->firstWhere('id', 'home-installed')['props']['label'],
        );
    }

    public function test_home_headlines_can_be_deleted_and_reset_restores_them(): void
    {
        $owner = User::factory()->superadmin()->create();
        $home = DashboardModel::query()->where('is_system', true)->where('name', 'Home')->firstOrFail();

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->call('toggleCustomizing');

        // Delete every widget through an empty geometry sync, then save.
        $component->call('syncLayout', []);
        $component->call('saveLayout', [])->assertHasNoErrors();

        // Home's layout lives on the shared system row, not a personal row.
        $this->assertSame([], $home->fresh()->layout['widgets']);

        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->call('resetLayout')
            ->assertHasNoErrors();

        // Reset restores the shipped default on the shared row.
        $this->assertCount(18, $home->fresh()->layout['widgets']);

        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->assertViewHas('grid', fn (array $grid): bool => count($grid['widgets']) === 18);
    }
}
