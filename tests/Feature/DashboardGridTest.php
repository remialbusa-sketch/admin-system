<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\DashboardLayout;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardGridTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $sourceRecordId, string $name, string $region): Account
    {
        return Account::create([
            'source_system' => 'product_database',
            'source_record_id' => $sourceRecordId,
            'customer_name' => $name,
            'region' => $region,
        ]);
    }

    private function installation(Account $account, array $overrides = []): Installation
    {
        return Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => uniqid('rec-'),
            'brand' => 'SYSMEX',
            'machine_type' => 'PORTABLE DEVICE',
            'device_status' => 'Active',
            'warranty_status' => 'Yes',
            'installation_date' => now()->subMonths(2),
            ...$overrides,
        ]);
    }

    public function test_home_renders_one_of_every_standard_widget(): void
    {
        $ncr = $this->account('acc-1', 'Example Hospital', 'NCR');
        $this->installation($ncr, ['warranty_end_date' => now()->addDays(21)]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Operations grid')
            // One widget per standard type, in the shipped default layout.
            ->assertSee('Fleet activation')
            ->assertSee('Installed products')
            ->assertSee('Warranty expiry countdown')
            ->assertSee('Installation momentum')
            ->assertSee('Active ratio')
            ->assertSee('Fleet state')
            ->assertSee('Machine mix')
            ->assertSee('Regional heat')
            ->assertSee('Regional position')
            ->assertSee('Warranty coverage goal')
            // The SLA countdown queue shows the upcoming warranty expiry.
            ->assertSee('Example Hospital')
            // Data table totals row renders from summed count columns.
            ->assertSee('Total');
    }

    public function test_widget_grid_expression_values_are_computed_live(): void
    {
        // One active, covered installation out of one installed: the KPI
        // formula active / installed * 100 must evaluate to 100.0%.
        $ncr = $this->account('acc-live', 'Live Check Hospital', 'NCR');
        $this->installation($ncr, ['warranty_end_date' => now()->addDays(40)]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(Dashboard::class)
            ->assertSee('100.0');
    }

    public function test_save_layout_persists_and_rerenders_in_order(): void
    {
        $user = User::factory()->president()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertOk()
            // Entering customize mode snapshots the layout into the draft.
            ->call('toggleCustomizing')
            ->call('saveLayout', [
                ['id' => 'trend-installs', 'type' => 'line_chart', 'w' => 8, 'h' => 3],
                ['id' => 'kpi-activation', 'type' => 'kpi_card', 'w' => 4, 'h' => 2],
            ]);

        $this->assertDatabaseHas('dashboard_layouts', ['user_id' => $user->id]);

        // The saved layout replaces the default: widgets not in it are gone.
        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('Installation momentum')
            ->assertDontSee('Warranty coverage goal');
    }

    public function test_editor_stays_in_customize_mode_until_done_is_clicked(): void
    {
        $user = User::factory()->president()->create();

        $component = Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->assertSet('customizing', true)
            // A live geometry sync (after a drag/resize/remove) must NOT
            // persist or leave edit mode...
            ->dispatch('dashboard-layout-sync', layout: [
                ['id' => 'kpi-activation', 'type' => 'kpi_card', 'w' => 8, 'h' => 2],
                ['id' => 'stat-installed', 'type' => 'stat', 'w' => 4, 'h' => 2],
            ])
            ->assertSet('customizing', true)
            ->assertSet('draftLayout.widgets.0.id', 'kpi-activation')
            ->assertSet('draftLayout.widgets.0.w', 8);

        // ...and nothing is persisted yet.
        $this->assertDatabaseMissing('dashboard_layouts', ['user_id' => $user->id]);

        // Done commits the draft and exits edit mode.
        $component
            ->dispatch('dashboard-layout-save', layout: [
                ['id' => 'kpi-activation', 'type' => 'kpi_card', 'w' => 8, 'h' => 2],
                ['id' => 'stat-installed', 'type' => 'stat', 'w' => 4, 'h' => 2],
            ])
            ->assertSet('customizing', false);

        $this->assertDatabaseHas('dashboard_layouts', ['user_id' => $user->id]);
    }

    public function test_cancel_discards_the_draft_without_persisting(): void
    {
        $user = User::factory()->president()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->dispatch('dashboard-layout-sync', layout: [
                ['id' => 'kpi-activation', 'type' => 'kpi_card', 'w' => 12, 'h' => 2],
            ])
            // Cancel leaves edit mode and throws the draft away.
            ->call('toggleCustomizing')
            ->assertSet('customizing', false)
            ->assertSet('draftLayout', []);

        $this->assertDatabaseMissing('dashboard_layouts', ['user_id' => $user->id]);
    }

    public function test_add_widget_appends_a_configured_widget_to_the_draft(): void
    {
        // The widget generator is opt-in: enable it for this test.
        config(['dashboard.allow_add_widgets' => true]);

        $user = User::factory()->president()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('addWidget', 'stat')
            // Still customizing — the widget lands in the draft...
            ->assertSet('customizing', true)
            // ...with the factory's default props (label "New stat").
            ->assertSee('New stat')
            ->call('saveLayout', []);

        $this->assertDatabaseHas('dashboard_layouts', ['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('New stat');
    }

    public function test_the_widget_generator_is_opt_in(): void
    {
        // Pin the gate off explicitly: the default in config may be flipped
        // on, but the hidden state must keep working whenever it's set.
        config(['dashboard.allow_add_widgets' => false]);

        $user = User::factory()->president()->create();

        // Flag off (default): the picker UI is absent and addWidget no-ops.
        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->assertSet('customizing', true)
            ->assertDontSee('Add widget')
            ->call('addWidget', 'stat')
            ->assertSee('Fleet activation')
            ->assertDontSee('New stat');

        $this->assertDatabaseMissing('dashboard_layouts', ['user_id' => $user->id]);
    }

    public function test_widget_settings_apply_to_the_draft_and_persist_on_done(): void
    {
        $user = User::factory()->president()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'kpi-activation')
            ->set('settingsProps.label', 'Activated fleet')
            ->set('settingsProps.sparkline', false)
            ->set('settingsProps.tone', 'success')
            ->call('applyWidgetSettings')
            ->assertSet('settingsError', null)
            // The modal STAYS OPEN (non-destructive Apply) so the user can
            // keep editing; the draft carries the applied settings live.
            ->assertSet('settingsApplied', true)
            ->assertSet('settingsWidgetId', 'kpi-activation')
            ->assertSet('customizing', true)
            ->assertSee('Activated fleet')
            ->call('saveLayout', []);

        $this->assertDatabaseHas('dashboard_layouts', ['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('Activated fleet');
    }

    public function test_widget_settings_reject_invalid_formulas(): void
    {
        $user = User::factory()->president()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'kpi-activation')
            ->set('settingsProps.label', 'Broken KPI')
            ->set('settingsProps.formula', '1 / 0 +')
            ->call('applyWidgetSettings')
            // The modal stays open with the validation error; the draft
            // still carries the ORIGINAL props.
            ->assertSet('settingsError', fn ($value) => str_contains((string) $value, 'Value formula'))
            ->assertSet('customizing', true)
            ->assertDontSee('Broken KPI');
    }

    public function test_settings_modal_seeds_a_visual_tree_for_arithmetic_formulas(): void
    {
        $user = User::factory()->president()->create();

        // KPI widget: the default formula reads back as a 5-node graph.
        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'kpi-activation')
            ->assertSet('settingsSchema.2.tree', function ($tree) {
                return is_array($tree)
                    && count($tree['nodes']) === 5
                    && filled($tree['root']);
            });

        // Progress widget: a single-metric formula is a one-node graph.
        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'progress-warranty')
            ->assertSet('settingsSchema.1.tree', function ($tree) {
                return is_array($tree)
                    && count($tree['nodes']) === 1
                    && $tree['nodes'][0]['kind'] === 'metric'
                    && $tree['nodes'][0]['value'] === 'warranty_ratio';
            });
    }

    public function test_advanced_formulas_fall_back_instead_of_a_visual_tree(): void
    {
        $user = User::factory()->president()->create();

        // A ternary formula cannot be drawn on the canvas: tree must be
        // null so the UI shows the advanced fallback instead of rewriting.
        DashboardLayout::create([
            'user_id' => $user->id,
            'layout' => ['version' => 1, 'widgets' => [
                ['id' => 'ternary-kpi', 'type' => 'kpi_card', 'w' => 4, 'h' => 2,
                    'props' => ['label' => 'Advanced', 'formula' => "active_ratio >= 90 ? 100 : 0"]],
            ]],
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'ternary-kpi')
            ->assertSet('settingsSchema.2.tree', null);
    }

    public function test_a_visually_built_formula_validates_and_applies(): void
    {
        $user = User::factory()->president()->create();

        // The tree editor pushes generated strings like this via
        // $wire.set; Apply must accept and store them unchanged.
        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'progress-warranty')
            ->set('settingsProps.current', '((active / installed) * 100)')
            ->call('applyWidgetSettings')
            ->assertSet('settingsError', null)
            ->assertSet('draftLayout.widgets.9.props.current', '((active / installed) * 100)')
            ->call('saveLayout', []);

        $this->assertDatabaseHas('dashboard_layouts', ['user_id' => $user->id]);

        // Reopening the settings after Apply must re-extract the full
        // visual graph from the stored string — the built blocks survive.
        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'progress-warranty')
            ->assertSet('settingsSchema.1.tree', function ($tree) {
                return is_array($tree)
                    && count($tree['nodes']) === 5
                    && filled($tree['root']);
            });
    }

    public function test_the_canvas_graph_persists_across_apply_and_reopen(): void
    {
        $user = User::factory()->president()->create();

        // Exactly what the editor writes: the raw canvas graph (blocks,
        // positions, connections) next to the expression string.
        $graph = [
            'nodes' => [
                ['id' => 'a', 'kind' => 'metric', 'value' => 'active', 'inputs' => [], 'x' => 14, 'y' => 14],
                ['id' => 'b', 'kind' => 'number', 'value' => '100', 'inputs' => [], 'x' => 14, 'y' => 92],
                ['id' => 'c', 'kind' => 'op', 'value' => '*', 'inputs' => ['a', 'b'], 'x' => 190, 'y' => 53],
            ],
            'root' => 'c',
        ];

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'progress-warranty')
            ->set('settingsProps.current', '(active * 100)')
            // The Apply button collects the canvas graphs from the editors'
            // in-memory registry and passes them INSIDE the Apply call —
            // the exact client path, immune to sync races.
            ->call('applyWidgetSettings', ['settingsProps.current_tree' => $graph])
            ->assertSet('settingsError', null)
            // The canvas graph lands in the widget props next to the string.
            ->assertSet('draftLayout.widgets.9.props.current_tree.nodes.2.value', '*')
            // Reopening re-seeds the EXACT canvas the user left behind —
            // same blocks, same positions — ready to edit, change, delete.
            ->call('editWidget', 'progress-warranty')
            ->assertSet('settingsSchema.1.graph.nodes.0.value', 'active')
            ->assertSet('settingsSchema.1.graph.nodes.2.inputs', ['a', 'b'])
            ->assertSet('settingsSchema.1.graph.nodes.2.x', 190)
            ->call('saveLayout', []);

        $this->assertDatabaseHas('dashboard_layouts', ['user_id' => $user->id]);
    }

    public function test_blocks_survive_close_done_and_a_fresh_page_load(): void
    {
        $user = User::factory()->president()->create();

        $graph = [
            'nodes' => [
                ['id' => 'a', 'kind' => 'metric', 'value' => 'active', 'inputs' => [], 'x' => 14, 'y' => 14],
                ['id' => 'b', 'kind' => 'number', 'value' => '100', 'inputs' => [], 'x' => 14, 'y' => 92],
                ['id' => 'c', 'kind' => 'op', 'value' => '*', 'inputs' => ['a', 'b'], 'x' => 190, 'y' => 53],
            ],
            'root' => 'c',
        ];

        // The user's exact flow: edit → Apply → Close → Done.
        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'progress-warranty')
            ->set('settingsProps.current', '(active * 100)')
            ->call('applyWidgetSettings', ['settingsProps.current_tree' => $graph])
            ->assertSet('settingsApplied', true)
            ->assertSet('settingsGraphCount', 1)
            ->call('cancelWidgetSettings')   // Close
            ->call('saveLayout', []);        // Done

        // A fresh page load later: Customize → gear → the canvas must be
        // exactly what was built.
        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'progress-warranty')
            ->assertSet('settingsSchema.1.graph.nodes.0.value', 'active')
            ->assertSet('settingsSchema.1.graph.nodes.2.value', '*')
            ->assertSet('settingsSchema.1.graph.nodes.2.x', 190);
    }

    public function test_a_tampered_canvas_graph_is_sanitized_on_apply(): void
    {
        $user = User::factory()->president()->create();

        $evilGraph = [
            'nodes' => [
                ['id' => 'ok', 'kind' => 'metric', 'value' => 'active', 'inputs' => [], 'x' => 1, 'y' => 2],
                ['id' => 'bad', 'kind' => '<script>', 'value' => str_repeat('x', 500), 'inputs' => [], 'x' => 1, 'y' => 2],
            ],
            'root' => 'ok',
        ];

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('toggleCustomizing')
            ->call('editWidget', 'progress-warranty')
            ->set('settingsProps.current', 'active')
            ->set('settingsProps.current_tree', $evilGraph)
            ->call('applyWidgetSettings')
            ->assertSet('settingsError', null)
            // Only the whitelisted node survives; junk is dropped.
            ->assertSet('draftLayout.widgets.9.props.current_tree.nodes', function ($nodes) {
                return is_array($nodes) && count($nodes) === 1 && $nodes[0]['id'] === 'ok';
            });
    }

    public function test_legacy_saved_layouts_migrate_to_the_new_widget_types(): void
    {
        $user = User::factory()->president()->create();

        // A layout saved before the widget-library replacement.
        DashboardLayout::create([
            'user_id' => $user->id,
            'layout' => ['version' => 1, 'widgets' => [
                ['id' => 'kpi-activation', 'type' => 'kpi', 'w' => 4, 'h' => 2,
                    'props' => ['label' => 'Fleet activation', 'formula' => 'pct(active, installed)']],
                ['id' => 'goal-warranty', 'type' => 'goal', 'w' => 4, 'h' => 2,
                    'props' => ['label' => 'Warranty coverage goal', 'current' => 'warranty_ratio', 'goal' => '60']],
                ['id' => 'trend-installs', 'type' => 'trend', 'w' => 8, 'h' => 3,
                    'props' => ['label' => 'Installation momentum', 'dataset' => 'install_trend', 'value_key' => 'count']],
                ['id' => 'pivot-regions', 'type' => 'pivot', 'w' => 6, 'h' => 3,
                    'props' => ['label' => 'Regional pivot']],
                ['id' => 'sla-warranty', 'type' => 'sla', 'w' => 4, 'h' => 3,
                    'props' => ['label' => 'Warranty expiry countdown']],
                // No equivalent in the standard library: dropped.
                ['id' => 'insights-signals', 'type' => 'insights', 'w' => 12, 'h' => 2, 'props' => []],
            ]],
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Fleet activation')
            ->assertSee('Warranty coverage goal')
            ->assertSee('Installation momentum')
            ->assertSee('Regional pivot')
            ->assertSee('Warranty expiry countdown')
            ->assertDontSee('AI insights')
            ->assertDontSee('Widget config error');
    }

    public function test_unknown_widget_types_are_dropped_from_user_layouts(): void
    {
        $user = User::factory()->president()->create();

        DashboardLayout::create([
            'user_id' => $user->id,
            'layout' => ['version' => 1, 'widgets' => [
                ['id' => 'evil', 'type' => 'iframe', 'w' => 12, 'h' => 2, 'props' => ['src' => 'https://evil.example']],
                [
                    'id' => 'kpi-activation', 'type' => 'kpi_card', 'w' => 4, 'h' => 2,
                    'props' => ['label' => 'Fleet activation', 'formula' => 'pct(active, installed)'],
                ],
            ]],
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Fleet activation')
            ->assertDontSee('evil.example');
    }

    public function test_a_broken_widget_formula_degrades_to_an_error_card(): void
    {
        $user = User::factory()->president()->create();

        DashboardLayout::create([
            'user_id' => $user->id,
            'layout' => ['version' => 1, 'widgets' => [
                // Unknown variable = config mistake (not empty data) → error card.
                ['id' => 'bad', 'type' => 'kpi_card', 'w' => 4, 'h' => 2, 'props' => ['label' => 'Broken', 'formula' => 'not_a_metric + 1']],
                ['id' => 'stat-ok', 'type' => 'stat', 'w' => 4, 'h' => 2, 'props' => ['label' => 'Still fine', 'metric' => 'installed']],
            ]],
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Widget config error')
            ->assertSee('Unknown variable')
            // The rest of the grid still renders.
            ->assertSee('Still fine');
    }

    public function test_widgets_show_no_data_instead_of_errors_when_scope_is_empty(): void
    {
        // No installations at all: the default KPI formula divides by zero,
        // which must render as "no data" — never an error card.
        $user = User::factory()->president()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Fleet activation')
            ->assertDontSee('Widget config error');
    }

    public function test_regional_manager_still_sees_their_scoped_grid(): void
    {
        $ncr = $this->account('acc-ncr-3', 'NCR Hospital', 'NCR');
        $this->installation($ncr);

        Livewire::actingAs(User::factory()->regionalManager('NCR')->create())
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Scoped to your region')
            ->assertSee('Operations grid')
            ->assertSee('NCR Hospital');
    }
}
