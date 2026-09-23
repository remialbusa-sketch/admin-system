<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Dashboard as DashboardModel;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WidgetMetricSourceTest extends TestCase
{
    use RefreshDatabase;

    private function sourcedDashboardFor(User $owner): DashboardModel
    {
        DynamicTable::create(['key' => 'pdb_testing', 'name' => 'PDB Testing', 'created_by' => $owner->id]);

        foreach (['r1', 'r2', 'r3'] as $index => $recordId) {
            DynamicRow::create([
                'table_key' => 'pdb_testing',
                'name' => 'Row '.($index + 1),
                'source_system' => 'test',
                'source_record_id' => $recordId,
            ]);
        }

        $dashboard = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'Testing PDB',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        $dashboard->sources()->create(['table_key' => 'pdb_testing', 'alias' => 't', 'position' => 0]);

        return $dashboard;
    }

    public function test_metric_dropdown_offers_connected_source_metrics(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->sourcedDashboardFor($owner);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->assertViewHas('metricOptions', function (array $options): bool {
                return ($options['t.rows'] ?? null) === 'PDB Testing · Rows'
                    && array_key_exists('installed', $options);
            });
    }

    public function test_sourceless_dashboard_keeps_pdb_metric_vocabulary(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'No sources',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->assertViewHas('metricOptions', function (array $options): bool {
                foreach (array_keys($options) as $key) {
                    if (str_contains($key, '.')) {
                        return false;
                    }
                }

                return array_key_exists('installed', $options);
            });
    }

    public function test_new_widget_defaults_to_the_connected_source_metric(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->sourcedDashboardFor($owner);

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->call('addWidget', 'kpi_card')
            ->assertHasNoErrors();

        $this->assertSame('t.rows', $component->get('draftLayout')['widgets'][0]['props']['metric']);
    }

    public function test_new_widget_keeps_pdb_default_without_sources(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'No sources',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->call('addWidget', 'kpi_card')
            ->assertHasNoErrors();

        $this->assertSame('installed', $component->get('draftLayout')['widgets'][0]['props']['metric']);
    }

    public function test_apply_accepts_namespaced_metric_and_rejects_unknown(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->sourcedDashboardFor($owner);

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->call('addWidget', 'kpi_card')
            ->assertHasNoErrors();

        $widgetId = $component->get('draftLayout')['widgets'][0]['id'];

        // A namespaced source metric passes validation and lands in the draft.
        $component
            ->call('editWidget', $widgetId)
            ->set('settingsProps.label', 'Test rows')
            ->set('settingsProps.metric', 't.rows')
            ->call('applyWidgetSettings')
            ->assertSet('settingsError', null);

        $this->assertSame('t.rows', $component->get('draftLayout')['widgets'][0]['props']['metric']);

        // An unknown metric is rejected with a settings error.
        $component
            ->call('editWidget', $widgetId)
            ->set('settingsProps.metric', 't.not_a_metric')
            ->call('applyWidgetSettings')
            ->assertSet('settingsError', 'Metric is not a known metric.');
    }

    public function test_edit_reseeds_an_unknown_metric_to_the_source_metric(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->sourcedDashboardFor($owner);

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->call('addWidget', 'kpi_card')
            ->assertHasNoErrors();

        $widgetId = $component->get('draftLayout')['widgets'][0]['id'];

        $component->call('editWidget', $widgetId);
        $component->set('settingsProps.metric', 'bogus_metric');
        $component->call('editWidget', $widgetId);

        $this->assertSame('t.rows', $component->get('settingsProps')['metric']);
    }

    public function test_kpi_widget_renders_the_connected_source_value(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->sourcedDashboardFor($owner);

        $dashboard->update([
            'layout' => [
                'version' => 1,
                'widgets' => [[
                    'id' => 'kpi-1',
                    'type' => 'kpi_card',
                    'w' => 4,
                    'h' => 2,
                    'props' => ['label' => 'Test rows', 'metric' => 't.rows'],
                ]],
            ],
        ]);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->assertViewHas('grid', function (array $grid): bool {
                return ($grid['widgets'][0]['data']['value'] ?? null) === 3;
            });
    }

    public function test_new_widget_keeps_expression_scoped_trend_metric(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->sourcedDashboardFor($owner);

        // Trend/momentum fields are evaluated by the expression engine
        // (PDB-scoped identifiers only) — they must not be seeded with a
        // namespaced source metric.
        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->call('addWidget', 'kpi_card')
            ->assertHasNoErrors();

        $props = $component->get('draftLayout')['widgets'][0]['props'];

        $this->assertSame('t.rows', $props['metric']);
        $this->assertSame('install_delta', $props['trend_metric']);
    }

    public function test_namespaced_trend_metric_drops_the_arrow_instead_of_breaking(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->sourcedDashboardFor($owner);

        // Props as produced by "Add widget" before the trend-seeding fix:
        // the momentum field carried a namespaced metric the expression
        // engine cannot tokenize.
        $dashboard->update([
            'layout' => [
                'version' => 1,
                'widgets' => [[
                    'id' => 'kpi-1',
                    'type' => 'kpi_card',
                    'w' => 4,
                    'h' => 2,
                    'props' => ['label' => 'Test rows', 'metric' => 't.rows', 'trend_metric' => 't.rows'],
                ]],
            ],
        ]);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->assertViewHas('grid', function (array $grid): bool {
                $widget = $grid['widgets'][0];

                return ($widget['view'] ?? null) !== 'components.dashboard.widgets.error'
                    && ($widget['data']['value'] ?? null) === 3
                    && array_key_exists('trend', $widget['data'] ?? [])
                    && $widget['data']['trend'] === null;
            });
    }

    public function test_settings_dropdown_groups_metrics_by_source(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->sourcedDashboardFor($owner);

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->call('addWidget', 'kpi_card')
            ->assertHasNoErrors();

        $widgetId = $component->get('draftLayout')['widgets'][0]['id'];
        $component->call('editWidget', $widgetId);

        $metricField = collect($component->get('settingsSchema'))
            ->firstWhere('key', 'metric');

        $this->assertSame('Installed products', $metricField['grouped_options']['Product Database']['installed'] ?? null);
        $this->assertSame('PDB Testing · Rows', $metricField['grouped_options']['PDB Testing']['t.rows'] ?? null);
    }

    public function test_grid_flags_widget_provenance(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->sourcedDashboardFor($owner);

        $dashboard->update([
            'layout' => [
                'version' => 1,
                'widgets' => [
                    [
                        'id' => 'kpi-1',
                        'type' => 'kpi_card',
                        'w' => 4,
                        'h' => 2,
                        'props' => ['label' => 'Test rows', 'metric' => 't.rows'],
                    ],
                    [
                        'id' => 'kpi-2',
                        'type' => 'kpi_card',
                        'w' => 4,
                        'h' => 2,
                        'props' => ['label' => 'Installed', 'metric' => 'installed'],
                    ],
                    [
                        'id' => 'bar-1',
                        'type' => 'bar_chart',
                        'w' => 6,
                        'h' => 3,
                        'props' => ['label' => 'Empty chart', 'dataset' => ''],
                    ],
                ],
            ],
        ]);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->assertViewHas('grid', function (array $grid): bool {
                $byId = collect($grid['widgets'])->keyBy('id');

                return ($byId['kpi-1']['provenance'] ?? null) === ['label' => 'PDB Testing', 'kind' => 'source']
                    && ($byId['kpi-2']['provenance'] ?? null) === ['label' => 'Product Database', 'kind' => 'pdb']
                    && ($byId['bar-1']['provenance'] ?? null) === ['label' => 'Not configured', 'kind' => 'none'];
            });
    }

    public function test_empty_widget_settings_show_no_data_hint(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->sourcedDashboardFor($owner);

        $dashboard->update([
            'layout' => [
                'version' => 1,
                'widgets' => [[
                    'id' => 'bar-1',
                    'type' => 'bar_chart',
                    'w' => 6,
                    'h' => 3,
                    'props' => ['label' => 'Empty chart', 'dataset' => ''],
                ]],
            ],
        ]);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing')
            ->call('editWidget', 'bar-1')
            ->assertSee('No data selected');
    }

    public function test_add_widget_modal_notes_sourceless_dashboards(): void
    {
        $owner = User::factory()->superadmin()->create();

        $bare = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'No sources',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $bare])
            ->assertSee('No tables connected');

        $sourced = $this->sourcedDashboardFor($owner);

        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $sourced])
            ->assertDontSee('No tables connected');
    }
}
