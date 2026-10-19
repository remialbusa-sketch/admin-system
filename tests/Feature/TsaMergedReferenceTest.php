<?php

namespace Tests\Feature;

use App\Livewire\TechnicalServiceAnalysis;
use App\Models\CustomTableColumn;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Support\Dashboard\TechnicalServiceReference;
use App\Support\SystemDashboards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Technical Service Analysis reference widgets are part of the page's
 * one customizable grid: they ship at the bottom, obey the Chart-fields
 * overwrite remap, honour saved layouts (back-fill once, removals persist)
 * and their gear settings behave like any other widget.
 */
class TsaMergedReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const WIDGET_IDS = ['tsa-service', 'tsa-year', 'tsa-month', 'tsa-model', 'tsa-tsp',
        'tsa-calls-month', 'tsa-service-model', 'tsa-service-total'];

    private function makeServiceColumn(string $name = 'Service Type'): CustomTableColumn
    {
        return CustomTableColumn::create(['table_key' => 'technical-reports', 'name' => $name, 'type' => 'text', 'position' => 1]);
    }

    public function test_reference_widgets_ship_at_the_bottom_of_the_customizable_grid(): void
    {
        $this->makeServiceColumn();
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'one', 'machine_type' => 'XN']);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', function (array $grid): bool {
                $ids = array_column($grid['widgets'], 'id');
                $kpiIndex = array_search('tsa-unassigned', $ids, true);
                $this->assertNotFalse($kpiIndex, 'The headline KPI row must still ship on the merged grid.');

                foreach (array_intersect(self::WIDGET_IDS, $ids) as $id) {
                    $this->assertGreaterThan($kpiIndex, array_search($id, $ids, true), sprintf('%s must sit below the KPI cards.', $id));
                }

                $this->assertSame('slicer', collect($grid['widgets'])->firstWhere('id', 'tsa-service')['type']);

                return true;
            });
    }

    public function test_a_saved_layout_gains_missing_reference_widgets_at_the_end(): void
    {
        $this->makeServiceColumn();
        SystemDashboards::storeLayout('Technical Service Analysis', ['version' => 1, 'widgets' => [
            ['id' => 'tsa-reports', 'type' => 'headline_kpi', 'w' => 12, 'h' => 2,
                'props' => ['label' => 'Reports moved', 'metric' => 'reports_total', 'context' => '', 'formula' => '', 'href' => '', 'decimals' => 0,
                    'context_metric' => '', 'context_suffix' => '', 'context_prefix' => '', 'context_decimals' => 0, 'variant' => 'lead', 'caption' => '', 'suffix' => '']],
        ]]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', function (array $grid): bool {
                $ids = array_column($grid['widgets'], 'id');
                $this->assertSame([0], array_keys(array_filter($ids, fn ($id) => $id === 'tsa-reports')), 'The saved widget keeps its position.');
                $this->assertSame('Reports moved', collect($grid['widgets'])->firstWhere('id', 'tsa-reports')['props']['label']);

                foreach (self::WIDGET_IDS as $id) {
                    $this->assertContains($id, $ids, sprintf('%s was back-filled after the saved widgets.', $id));
                    $this->assertGreaterThan(array_search('tsa-reports', $ids, true), array_search($id, $ids, true));
                }

                return true;
            });
    }

    public function test_chart_field_overrides_retarget_reference_widgets_of_a_saved_layout(): void
    {
        $custom = $this->makeServiceColumn('Custom Type');
        SystemDashboards::storeLayout('Technical Service Analysis', ['version' => 1, 'widgets' => [
            ['id' => 'tsa-reports', 'type' => 'headline_kpi', 'w' => 12, 'h' => 2,
                'props' => ['label' => 'Reports', 'metric' => 'reports_total', 'context' => '', 'formula' => '', 'href' => '', 'decimals' => 0,
                    'context_metric' => '', 'context_suffix' => '', 'context_prefix' => '', 'context_decimals' => 0, 'variant' => 'lead', 'caption' => '', 'suffix' => '']],
        ]]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->set('analysisColumns.service', 'custom_'.$custom->id)
            ->assertViewHas('tsaGrid', function (array $grid) use ($custom): bool {
                $charts = collect($grid['widgets'])->keyBy('id');
                $this->assertSame('tr.custom_'.$custom->id, $charts['tsa-service-total']['props']['column']);
                $this->assertSame('tr.custom_'.$custom->id, $charts['tsa-service']['props']['column']);
                $this->assertSame('tr.custom_'.$custom->id, $charts['tsa-service-model']['props']['series_column']);

                return true;
            });
    }

    public function test_reference_widgets_without_a_service_column_are_omitted(): void
    {
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'one', 'machine_type' => 'XN']);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', function (array $grid): bool {
                $ids = array_column($grid['widgets'], 'id');
                foreach (['tsa-service', 'tsa-service-model', 'tsa-service-total'] as $id) {
                    $this->assertNotContains($id, $ids, sprintf('%s must not ship when no service column exists.', $id));
                }
                foreach (['tsa-model', 'tsa-calls-month', 'tsa-year', 'tsa-month', 'tsa-tsp'] as $id) {
                    $this->assertContains($id, $ids);
                }
                $ids = array_column($grid['widgets'], 'id');
                foreach (['tsa-service', 'tsa-service-model', 'tsa-service-total'] as $id) {
                    $this->assertNotContains($id, $ids, sprintf('%s must not ship when no service column exists.', $id));
                }
                foreach (['tsa-model', 'tsa-calls-month', 'tsa-year', 'tsa-month', 'tsa-tsp'] as $id) {
                    $this->assertContains($id, $ids);
                }
                foreach ($grid['widgets'] as $widget) {
                    $this->assertStringNotContainsString('error', (string) ($widget['view'] ?? ''), sprintf('%s degrades to an error card.', $widget['id']));
                }

                return true;
            });
    }

    public function test_reference_widgets_are_gear_editable_like_any_other_widget(): void
    {
        $this->makeServiceColumn();
        $custom = $this->makeServiceColumn('Custom Type');

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->call('editWidgetFor', 'tsa', 'tsa-service-model')
            ->assertViewHas('tsaState', function (array $state) use ($custom): bool {
                $this->assertTrue($state['customizing']);
                $field = collect($state['settingsSchema'])->firstWhere('key', 'column');
                $this->assertIsArray($field['grouped_options'] ?? null, 'The category column offers grouped options.');
                $this->assertArrayHasKey('tr', $field['grouped_options']);
                $this->assertArrayHasKey('tr.custom_'.$custom->id, $field['grouped_options']['tr']);
                $this->assertArrayHasKey('tr.machine_type', $field['grouped_options']['tr']);

                return true;
            })
            ->set('widgetGrids.tsa.settingsProps.column', 'tr.custom_'.$custom->id)
            ->call('applyWidgetSettingsFor', 'tsa')
            ->assertViewHas('tsaState', fn (array $state) => ((collect($state['draftLayout']['widgets'])->firstWhere('id', 'tsa-service-model')['props']) ?? [])['column'] === 'tr.custom_'.$custom->id);
    }

    public function test_a_broken_column_choice_is_rejected_by_the_settings_gear(): void
    {
        $this->makeServiceColumn();

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->call('editWidgetFor', 'tsa', 'tsa-service-total')
            ->set('widgetGrids.tsa.settingsProps.column', 'tr.machine_type); DROP TABLE technical_reports;')
            ->call('applyWidgetSettingsFor', 'tsa')
            ->assertViewHas('tsaState', fn (array $state) => is_string($state['settingsError']));
    }

    public function test_choosing_a_slicer_column_clears_its_selection_on_apply(): void
    {
        $custom = $this->makeServiceColumn();

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->set('slicerSelections.tsa-service', ['Repair'])
            ->call('toggleCustomizingFor', 'tsa')
            ->call('editWidgetFor', 'tsa', 'tsa-service')
            ->set('widgetGrids.tsa.settingsProps.column', 'tr.custom_'.$custom->id)
            ->call('applyWidgetSettingsFor', 'tsa')
            ->assertSet('slicerSelections.tsa-service', null);
    }

    public function test_slicer_filters_and_chip_labels_follow_the_resolved_layout(): void
    {
        $this->makeServiceColumn();
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'one', 'machine_type' => 'XN']);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleSlicerValue', 'tsa-model', 'XN')
            ->assertViewHas('analysisFilters', fn (array $filters) => ($filters['tsa-model']['label'] ?? '') === 'Model')
            ->call('clearAnalysisFilters')
            ->assertViewHas('analysisFilters', []);
    }

    public function test_done_saves_the_draft_and_removals_persist_without_resurrection(): void
    {
        $this->makeServiceColumn();

        // Keep only two reference widgets: everything else (KPIs included)
        // counts as removed by the admin once the merge-aware draft is saved.
        $geometry = [['id' => 'tsa-service', 'w' => 12, 'h' => 2], ['id' => 'tsa-year', 'w' => 3, 'h' => 2]];

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->assertViewHas('tsaState', fn (array $state) => collect($state['draftLayout']['widgets'])->pluck('id')->contains('tsa-service-model'),
                'The draft starts from the resolved merged layout, not bare KPIs.')
            ->call('saveWidgetGrid', $geometry, 'tsa');

        $stored = SystemDashboards::coreRow('Technical Service Analysis')->layout;
        $this->assertSame(['tsa-service', 'tsa-year'], array_column($stored['widgets'] ?? [], 'id'),
            'Done stores the draft order, deletions included.');
        $this->assertSame(3, $stored['widgets'][1]['w'], 'Saved spans are the editor-clamped ones.');

        // Re-render: no back-fill resurrection after an explicit save.
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', fn (array $grid) => array_column($grid['widgets'], 'id') === ['tsa-service', 'tsa-year']);
    }

    public function test_reset_restores_the_full_reference_set_below_the_kpis(): void
    {
        $this->makeServiceColumn();
        SystemDashboards::storeLayout('Technical Service Analysis', ['version' => 1, 'widgets' => []]);

        // An explicitly saved empty layout is admin intent — it renders empty.
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', fn (array $grid) => $grid['widgets'] === [])
            ->call('resetWidgetGrid', 'tsa')
            ->assertViewHas('tsaGrid', function (array $grid): bool {
                $ids = array_column($grid['widgets'], 'id');
                $this->assertContains('tsa-service-model', $ids, 'Reset brings the shipped default back, reference set included.');
                $this->assertGreaterThan(array_search('tsa-unassigned', $ids, true), array_search('tsa-service-model', $ids, true));

                return true;
            });

        $this->assertNull(SystemDashboards::coreRow('Technical Service Analysis')->layout);
    }

    public function test_a_marked_layout_gains_the_new_reference_widget_once(): void
    {
        $this->makeServiceColumn();

        // The admin's saved layout: two widgets only, marked — so the other
        // reference widgets stay deleted — and written before the TSP filter
        // existed (version 1).
        SystemDashboards::storeLayout('Technical Service Analysis', ['version' => 1, 'reference_marked' => true, 'widgets' => [
            ['id' => 'tsa-reports', 'type' => 'headline_kpi', 'w' => 12, 'h' => 2,
                'props' => ['label' => 'Reports', 'metric' => 'reports_total', 'context' => '', 'formula' => '', 'href' => '', 'decimals' => 0,
                    'context_metric' => '', 'context_suffix' => '', 'context_prefix' => '', 'context_decimals' => 0, 'variant' => 'lead', 'caption' => '', 'suffix' => '']],
            ['id' => 'tsa-year', 'type' => 'slicer', 'w' => 3, 'h' => 2,
                'props' => ['label' => 'Year', 'column' => 'tr.service_started_at', 'mode' => 'year', 'columns' => 3, 'multiple' => true]],
        ]]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', function (array $grid): bool {
                $ids = array_column($grid['widgets'], 'id');
                $this->assertContains('tsa-tsp', $ids, 'The new reference widget reaches a marked layout once.');
                $this->assertSame('tr.tsp_name', collect($grid['widgets'])->firstWhere('id', 'tsa-tsp')['props']['column']);
                foreach (['tsa-month', 'tsa-model', 'tsa-calls-month'] as $id) {
                    $this->assertNotContains($id, $ids, sprintf('%s stays deleted.', $id));
                }

                return true;
            });
    }

    public function test_the_introduced_tsp_filter_stays_deleted_after_done(): void
    {
        $this->makeServiceColumn();
        SystemDashboards::storeLayout('Technical Service Analysis', ['version' => 1, 'reference_marked' => true, 'widgets' => [
            ['id' => 'tsa-year', 'type' => 'slicer', 'w' => 3, 'h' => 2,
                'props' => ['label' => 'Year', 'column' => 'tr.service_started_at', 'mode' => 'year', 'columns' => 3, 'multiple' => true]],
        ]]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->assertViewHas('tsaState', fn (array $state) => collect($state['draftLayout']['widgets'])->pluck('id')->contains('tsa-tsp'))
            ->call('saveWidgetGrid', [['id' => 'tsa-year', 'w' => 3, 'h' => 2]], 'tsa'); // Done with the TSP filter deleted.

        $stored = SystemDashboards::coreRow('Technical Service Analysis')->layout;
        $this->assertSame(TechnicalServiceReference::LAYOUT_VERSION, $stored['version'], 'Done stamps the current layout version.');
        $this->assertSame(['tsa-year'], array_column($stored['widgets'], 'id'));

        // Re-render: the introduction never repeats after an explicit save.
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', fn (array $grid) => array_column($grid['widgets'], 'id') === ['tsa-year']);
    }

    public function test_the_tsp_filter_lists_display_names_and_filters_by_the_stored_id(): void
    {
        $this->makeServiceColumn();
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'one', 'machine_type' => 'XN', 'tsp_name' => 'TSP-1', 'tsp_display_name' => 'Reynan Ochea']);
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'two', 'machine_type' => 'XN', 'tsp_name' => 'TSP-1', 'tsp_display_name' => 'Reynan Ochea']);
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'three', 'machine_type' => 'XN', 'tsp_name' => 'TSP-2', 'tsp_display_name' => 'Jun Cruz']);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', function (array $grid): bool {
                $widget = collect($grid['widgets'])->firstWhere('id', 'tsa-tsp');
                $this->assertSame('slicer', $widget['type']);
                $options = collect($widget['data']['options'])->keyBy('value');
                $this->assertSame('Reynan Ochea', $options['TSP-1']['label'], 'The button shows the display name.');
                $this->assertSame(2, $options['TSP-1']['count']);
                $this->assertSame('Jun Cruz', $options['TSP-2']['label']);

                return true;
            })
            ->call('toggleSlicerValue', 'tsa-tsp', 'TSP-1')
            ->assertViewHas('metrics', fn (array $metrics) => $metrics['reports_total'] === 2, 'Selection filters by the stored tsp_name, not the label.');
    }

    public function test_the_tsp_filter_keeps_user_configured_props(): void
    {
        $this->makeServiceColumn();

        // The admin re-pointed the TSP filter at another column and renamed
        // it — no Chart-fields role pins it back, unlike the other slicers.
        SystemDashboards::storeLayout('Technical Service Analysis', ['version' => TechnicalServiceReference::LAYOUT_VERSION, 'reference_marked' => true, 'widgets' => [
            ['id' => 'tsa-tsp', 'type' => 'slicer', 'w' => 6, 'h' => 3,
                'props' => ['label' => 'My TSPs', 'column' => 'tr.brand', 'mode' => 'value', 'columns' => 3, 'multiple' => true]],
        ]]);
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'one', 'machine_type' => 'XN', 'brand' => 'AEONMED']);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', function (array $grid): bool {
                $widget = collect($grid['widgets'])->firstWhere('id', 'tsa-tsp');
                $this->assertSame('My TSPs', $widget['props']['label']);
                $this->assertSame('tr.brand', $widget['props']['column'], 'The user-picked column is never re-pinned.');
                $this->assertSame(6, $widget['w']);
                $this->assertSame(3, $widget['h']);
                $this->assertSame(['AEONMED'], array_column($widget['data']['options'], 'value'));

                return true;
            });
    }

    public function test_url_slicers_of_a_removed_widget_do_not_filter_anything(): void
    {
        $this->makeServiceColumn();
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'one', 'machine_type' => 'XN']);

        // The admin saved a layout without slicers — their removal persists,
        // so a stale URL slicer selection must filter nothing.
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->call('saveWidgetGrid', [['id' => 'tsa-reports', 'w' => 12, 'h' => 3]], 'tsa');

        Livewire::withQueryParams(['slicers' => ['tsa-model' => ['XN']]])
            ->actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['reports_total'] === 1)
            ->assertViewHas('analysisFilters', []);
    }
}
