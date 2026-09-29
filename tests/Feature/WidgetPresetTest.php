<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\TechnicalServiceAnalysis;
use App\Livewire\TspAnalytics;
use App\Models\Dashboard as DashboardModel;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Support\Dashboard\WidgetPresets;
use App\Support\Dashboard\WidgetRegistry;
use App\Support\SystemDashboards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase C: the deleted hardcoded sections live on as one-click presets —
 * every preset resolves to a registered widget type, pages only offer
 * their own presets, and adding one lands a fully-configured widget in
 * the shared draft/layout.
 */
class WidgetPresetTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    private function viewer(): User
    {
        return User::factory()->president()->create();
    }

    public function test_every_preset_resolves_to_a_registered_widget_type(): void
    {
        $types = app(WidgetRegistry::class)->types();

        $this->assertNotEmpty(WidgetPresets::all());

        foreach (WidgetPresets::all() as $key => $preset) {
            $this->assertContains($preset['type'], $types, "Preset {$key} type is not registered.");
            $this->assertContains($preset['page'], WidgetPresets::PAGES, "Preset {$key} page is unknown.");

            foreach (['title', 'description', 'type', 'w', 'h', 'props'] as $field) {
                $this->assertArrayHasKey($field, $preset, "Preset {$key} misses {$field}.");
            }
        }
    }

    public function test_home_add_preset_appends_a_configured_widget(): void
    {
        $admin = $this->superadmin();

        $component = Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('addPreset', 'regional-breakdown')
            ->assertHasNoErrors();

        $widgets = $component->get('draftLayout.widgets');
        $added = end($widgets);

        $this->assertSame('table', $added['type']);
        $this->assertSame('regions', $added['props']['dataset'] ?? null);
        $this->assertSame('region', $added['props']['label_key'] ?? null);

        // Unknown keys and other pages' presets are ignored, never crash.
        $second = Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->call('toggleCustomizing');

        $before = count($second->get('draftLayout.widgets'));

        $second
            ->call('addPreset', 'does-not-exist')
            ->call('addPreset', 'report-status')
            ->assertHasNoErrors();

        $this->assertCount($before, $second->get('draftLayout.widgets'));
    }

    public function test_presets_are_scoped_to_their_page(): void
    {
        $admin = $this->superadmin();

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertViewHas('widgetPresets', function (array $presets): bool {
                return array_key_exists('regional-breakdown', $presets)
                    && ! array_key_exists('report-status', $presets);
            });

        $personal = DashboardModel::create([
            'owner_id' => $admin->id,
            'name' => 'Personal',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        // Personal dashboards offer types only — core presets would not fit
        // their vocabulary.
        Livewire::actingAs($admin)
            ->test(Dashboard::class, ['dashboard' => $personal])
            ->assertViewHas('widgetPresets', fn (array $presets): bool => $presets === []);
    }

    public function test_tsa_add_preset_lands_in_the_shared_layout(): void
    {
        $admin = $this->superadmin();

        Livewire::actingAs($admin)
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->call('addWidgetFor', 'tsa', 'report-status')
            ->call('addWidgetFor', 'tsa', 'regional-breakdown')
            ->call('saveWidgetGrid', [], 'tsa')
            ->assertHasNoErrors();

        $widgets = SystemDashboards::coreRow('Technical Service Analysis')->layout['widgets'] ?? [];

        $donut = collect($widgets)->firstWhere('type', 'donut_chart');

        $this->assertNotNull($donut);
        $this->assertSame('status', $donut['props']['dataset'] ?? null);

        // The Home preset was refused: wrong page, nothing stored for it.
        $this->assertSame(8, count($widgets));
    }

    public function test_tsa_preset_widgets_render_live_data(): void
    {
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'TR-1',
            'reference_number' => 'TR-1',
            'customer_name' => 'Alpha Hospital',
            'tsp_name' => 'Alice',
            'brand' => 'SYSMEX',
            'service_status' => 'On Hold',
            'service_completed_at' => now()->subDays(2),
            'repair_time_hours' => 2,
        ]);

        SystemDashboards::storeLayout('Technical Service Analysis', [
            'version' => 1,
            'widgets' => [WidgetPresets::make('report-status')],
        ]);

        // 'On Hold' appears nowhere else on the page (no KPI uses it) — it
        // proves the preset's status dataset is wired and rendering.
        $this->actingAs($this->viewer())
            ->get(route('technical-service-analysis'))
            ->assertOk()
            ->assertSee('Report status mix')
            ->assertSee('On Hold');
    }

    public function test_tsp_add_preset_lands_in_the_shared_layout(): void
    {
        $admin = $this->superadmin();

        Livewire::actingAs($admin)
            ->test(TspAnalytics::class)
            ->call('toggleCustomizingFor', 'tsp')
            ->call('addWidgetFor', 'tsp', 'regional-table')
            ->call('saveWidgetGrid', [], 'tsp')
            ->assertHasNoErrors();

        $widgets = SystemDashboards::coreRow('TSP Analytics')->layout['widgets'] ?? [];

        $table = collect($widgets)->firstWhere('type', 'table');

        $this->assertNotNull($table);
        $this->assertSame('regional', $table['props']['dataset'] ?? null);
        $this->assertSame(5, count($widgets));
    }

    public function test_shared_grid_gear_calls_the_grid_aware_action(): void
    {
        $admin = $this->superadmin();

        // The shared grid partial must call editWidgetFor(grid, id) — the
        // Dashboard-only editWidget(id) does not exist on page hosts.
        Livewire::actingAs($admin)
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->assertSee('editWidgetFor')
            ->assertDontSee("editWidget('", false);
    }

    public function test_dashboard_delegates_grid_aware_gear_to_edit_widget(): void
    {
        $admin = $this->superadmin();

        $id = Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('addWidget', 'stat')
            ->get('draftLayout.widgets.0.id');

        $this->assertIsString($id);

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('addWidget', 'stat')
            ->call('editWidgetFor', 'default', $id)
            ->assertSet('settingsWidgetId', $id);
    }

    public function test_home_hardcoded_sections_are_gone(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('aria-label="Regional position"', false)
            ->assertDontSee('aria-label="Fleet state and installation trend"', false)
            ->assertDontSee('aria-label="Base composition"', false)
            ->assertDontSee('aria-label="Top accounts"', false)
            ->assertDontSee('aria-label="Attention needed"', false);
    }

    public function test_tsa_hardcoded_sections_are_gone(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('technical-service-analysis'))
            ->assertOk()
            ->assertDontSee('aria-label="Status mix and completion trend"', false)
            ->assertDontSee('aria-label="Workload and brand mix"', false);
    }

    public function test_tsp_hardcoded_sections_are_gone(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('tsp-analytics'))
            ->assertOk()
            ->assertDontSee('Completed technical reports')
            ->assertDontSee('Coverage notes')
            ->assertDontSee('Per-TSP performance')
            ->assertDontSee('Regional performance');
    }
}
