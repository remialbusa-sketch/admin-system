<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\WidgetWizard;
use App\Models\Account;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\Dashboard as DashboardModel;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Services\DashboardColumnService;
use App\Services\ProductDashboardService;
use App\Services\TableAggregationService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\DashboardLayoutEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardColumnBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function request(array $attributes = []): ServiceRequest
    {
        return ServiceRequest::create($attributes + ['source_system' => 'test', 'source_record_id' => uniqid(), 'customer_name' => 'Test hospital']);
    }

    private function chart(array $props = [], array $filters = []): array
    {
        return app(DashboardColumnService::class)->chart($props + ['column' => 'calls.machine_type', 'aggregation' => 'count'], ['calls' => 'service-requests'], $filters);
    }

    public function test_charts_group_by_columns_and_series_without_the_old_eight_item_cap(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());
        foreach (range(1, 15) as $i) {
            $this->request(['machine_type' => sprintf('Model %02d', $i), 'service_type' => 'Repair']);
        }
        $this->request(['machine_type' => 'Model 01', 'service_type' => 'Installation']);
        $chart = $this->chart(['series_column' => 'calls.service_type']);
        $this->assertCount(15, $chart['points']);
        $this->assertSame(['Installation', 'Repair'], $chart['series']);
        $this->assertSame([1.0, 1.0], $chart['points'][0]['values']);
        $this->assertSame([0.0, 1.0], $chart['points'][1]['values']);
        $this->assertFalse($chart['truncated']);
        $this->assertTrue($this->chart(['limit' => 10])['truncated']);
    }

    public function test_year_and_month_slicers_intersect_and_options_ignore_their_own_selection(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());
        $this->request(['machine_type' => 'A', 'date_needed' => '2024-01-02']);
        $this->request(['machine_type' => 'B', 'date_needed' => '2025-01-03']);
        $this->request(['machine_type' => 'C', 'date_needed' => '2025-02-04']);
        $filters = ['slicers' => [
            'year' => ['table_key' => 'service-requests', 'key' => 'date_needed', 'mode' => 'year', 'values' => ['2025']],
            'month' => ['table_key' => 'service-requests', 'key' => 'date_needed', 'mode' => 'month', 'values' => ['01']],
        ]];
        $this->assertSame([['label' => 'B', 'values' => [1.0]]], $this->chart([], $filters)['points']);
        $choices = app(DashboardColumnService::class)->choices(['column' => 'calls.date_needed', 'mode' => 'month'], ['calls' => 'service-requests'], $filters, 'month');
        $this->assertSame(['January', 'February'], array_column($choices['options'], 'label'));
        $this->assertSame(['01'], $choices['selected']);
        $this->assertSame(1, app(TableAggregationService::class)->summary('service-requests', null, $filters)['metrics']['rows']);
    }

    public function test_slicer_options_label_person_fields_with_their_display_name_but_filter_by_the_stored_id(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'one', 'tsp_name' => 'TSP-9', 'tsp_display_name' => 'Maria Santos']);
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'two', 'tsp_name' => 'TSP-9', 'tsp_display_name' => 'Maria Santos']);

        $choices = app(DashboardColumnService::class)->choices(['column' => 'tr.tsp_name', 'mode' => 'value'], ['tr' => 'technical-reports'], [], 'tsp');
        $this->assertSame([['value' => 'TSP-9', 'label' => 'Maria Santos', 'count' => 2]], $choices['options']);

        // A selected value the other filters exclude keeps its display label.
        $filters = ['slicers' => [
            'tsp' => ['table_key' => 'technical-reports', 'key' => 'tsp_name', 'mode' => 'value', 'values' => ['TSP-9']],
            'model' => ['table_key' => 'technical-reports', 'key' => 'machine_type', 'mode' => 'value', 'values' => ['NO-SUCH-MODEL']],
        ]];
        $choices = app(DashboardColumnService::class)->choices(['column' => 'tr.tsp_name', 'mode' => 'value'], ['tr' => 'technical-reports'], $filters, 'tsp');
        $this->assertSame([['value' => 'TSP-9', 'label' => 'Maria Santos', 'count' => 0]], $choices['options']);
    }

    public function test_monthly_trends_sort_chronologically_and_blank_categories_are_counted(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());
        $this->request(['date_needed' => '2025-02-01']);
        $this->request(['date_needed' => '2024-12-01']);
        $this->request();
        $points = $this->chart(['column' => 'calls.date_needed', 'grouping' => 'year_month'])['points'];
        $this->assertSame(['(Blank)', '2024-12', '2025-02'], array_column($points, 'label'));
    }

    public function test_numeric_calculations_use_numeric_columns_and_preserve_negative_values(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());
        foreach ([2, 4, -3] as $value) {
            TechnicalReport::create(['source_system' => 'test', 'source_record_id' => uniqid(), 'brand' => 'A', 'repair_time_hours' => $value]);
        }
        foreach (['sum' => 3.0, 'avg' => 1.0, 'min' => -3.0, 'max' => 4.0] as $aggregation => $expected) {
            $chart = app(DashboardColumnService::class)->chart(['column' => 'reports.brand', 'value_column' => 'reports.repair_time_hours', 'aggregation' => $aggregation], ['reports' => 'technical-reports'], []);
            $this->assertSame([$expected], $chart['points'][0]['values']);
        }
    }

    public function test_dynamic_date_and_number_columns_share_the_same_filter_scope_and_exclude_archived_rows(): void
    {
        $owner = User::factory()->superadmin()->create();
        $this->actingAs($owner);
        DynamicTable::create(['key' => 'calls', 'name' => 'Calls', 'created_by' => $owner->id]);
        $date = CustomTableColumn::create(['table_key' => 'calls', 'name' => 'Call Login Date', 'type' => 'date', 'position' => 0]);
        $amount = CustomTableColumn::create(['table_key' => 'calls', 'name' => 'Repair Time', 'type' => 'number', 'position' => 1]);
        foreach ([['2024-01-01', 5, null], ['2025-01-01', 8, null], ['2025-01-01', 100, now()]] as [$day, $value, $archive]) {
            $row = DynamicRow::create(['table_key' => 'calls', 'name' => 'Repair', 'archived_at' => $archive]);
            CustomTableColumnValue::create(['custom_column_id' => $date->id, 'row_id' => $row->id, 'value' => $day, 'value_date' => $day]);
            CustomTableColumnValue::create(['custom_column_id' => $amount->id, 'row_id' => $row->id, 'value' => $value, 'value_number' => $value]);
        }
        $filters = ['slicers' => ['year' => ['table_key' => 'calls', 'key' => 'custom_'.$date->id, 'mode' => 'year', 'values' => ['2025']]]];
        $result = app(DashboardColumnService::class)->chart(['column' => 'c.name', 'value_column' => 'c.custom_'.$amount->id, 'aggregation' => 'sum'], ['c' => 'calls'], $filters);
        $this->assertSame([8.0], $result['points'][0]['values']);
        $this->assertSame(1, app(TableAggregationService::class)->summary('calls', null, $filters)['metrics']['rows']);
    }

    public function test_regional_scope_is_enforced_even_when_the_requested_filter_is_all_regions(): void
    {
        $this->actingAs(User::factory()->regionalManager('NCR')->create());
        $this->request(['machine_type' => 'Allowed', 'region' => 'NCR']);
        $this->request(['machine_type' => 'Other', 'region' => 'Visayas']);
        $this->assertSame(['Allowed'], array_column($this->chart([], ['region' => 'All regions'])['points'], 'label'));
    }

    public function test_unconnected_and_injected_columns_are_rejected_before_sql(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());
        $this->expectException(ValidationException::class);
        $this->chart(['column' => 'calls.machine_type) from users --']);
    }

    public function test_livewire_slicers_support_multiselect_clear_and_ignore_unknown_url_definitions(): void
    {
        $owner = User::factory()->superadmin()->create();
        $this->request(['machine_type' => 'A']);
        $this->request(['machine_type' => 'B']);
        $board = DashboardModel::create(['owner_id' => $owner->id, 'name' => 'Call dashboard', 'layout' => ['version' => 1, 'widgets' => [
            ['id' => 'model', 'type' => 'slicer', 'w' => 3, 'h' => 3, 'props' => ['label' => 'Model', 'column' => 'calls.machine_type', 'multiple' => true]],
            ['id' => 'chart', 'type' => 'column_chart', 'w' => 8, 'h' => 4, 'props' => ['label' => 'Calls', 'column' => 'calls.machine_type']],
        ]]]);
        $board->sources()->create(['table_key' => 'service-requests', 'alias' => 'calls', 'position' => 0]);
        Livewire::actingAs($owner)->test(Dashboard::class, ['dashboard' => $board->id])
            ->set('slicerSelections', ['unknown' => ['malicious']])
            ->assertDontSee('Clear filters')
            ->call('toggleSlicerValue', 'model', 'A')->assertSet('slicerSelections.model', ['A'])
            ->assertSee('Clear filters (1)')
            ->assertViewHas('activeSlicers', fn ($slicers) => count($slicers) === 1 && $slicers[0]['values'] === 'A')
            ->assertViewHas('grid', fn ($grid) => count($grid['widgets'][1]['data']['points']) === 1)
            ->call('toggleSlicerValue', 'model', 'B')->assertSet('slicerSelections.model', ['A', 'B'])
            ->call('toggleSlicerValue', 'model', 'A')->assertSet('slicerSelections.model', ['B'])
            ->call('clearFilters')->assertSet('slicerSelections', [])
            ->assertViewHas('grid', fn ($grid) => count($grid['widgets'][1]['data']['points']) === 2);
    }

    public function test_wizard_creates_a_configured_column_chart_and_slicer_with_preview(): void
    {
        $owner = User::factory()->superadmin()->create();
        $this->request(['machine_type' => 'XN', 'service_type' => 'Repair']);
        $board = DashboardModel::create(['owner_id' => $owner->id, 'name' => 'Custom charts', 'layout' => ['version' => 1, 'widgets' => []]]);
        foreach (['column_chart', 'slicer'] as $type) {
            $component = Livewire::actingAs($owner)->test(WidgetWizard::class, ['table' => 'service-requests', 'dashboard' => $board->id])
                ->set('widgetType', $type)->set('title', 'Service calls')->set('builderProps.column', 'machine_type')
                ->assertViewHas('preview', fn ($preview) => $preview !== null)
                ->call('create')->assertHasNoErrors()->assertRedirect(route('dashboards.show', $board));
        }
        $widgets = $board->fresh()->layout['widgets'];
        $this->assertCount(2, $widgets);
        $this->assertSame('service_requests.machine_type', $widgets[0]['props']['column']);
        $this->assertSame('service_requests.machine_type', $widgets[1]['props']['column']);
        $this->actingAs($owner);
        $context = DashboardContext::fromSummary('All regions', '12M', [])->withSources(['service_requests' => ['table_key' => 'service-requests']]);
        $grid = app(DashboardLayoutEngine::class)->build($board->fresh()->layout, $context);
        $this->assertSame('components.dashboard.widgets.column-chart', $grid['widgets'][0]['view']);
        $this->assertSame('XN', $grid['widgets'][0]['data']['points'][0]['label']);
    }

    public function test_dashboard_settings_validate_columns_and_save_the_builder_configuration(): void
    {
        $owner = User::factory()->superadmin()->create();
        $this->request(['machine_type' => 'XN', 'service_type' => 'Repair']);
        $board = DashboardModel::create(['owner_id' => $owner->id, 'name' => 'Settings test', 'layout' => ['version' => 1, 'widgets' => [
            ['id' => 'chart', 'type' => 'column_chart', 'w' => 8, 'h' => 4, 'props' => []],
        ]]]);
        $board->sources()->create(['table_key' => 'service-requests', 'alias' => 'calls', 'position' => 0]);
        Livewire::actingAs($owner)->test(Dashboard::class, ['dashboard' => $board->id])
            ->call('toggleCustomizing')->call('editWidget', 'chart')
            ->set('settingsProps.column', 'calls.not_a_column')->call('applyWidgetSettings')
            ->assertSet('settingsError', 'Choose a column from a connected table.')
            ->set('settingsProps.column', 'calls.machine_type')->set('settingsProps.series_column', 'calls.service_type')
            ->set('settingsProps.orientation', 'horizontal')->set('settingsProps.limit', 50)
            ->call('applyWidgetSettings')->assertSet('settingsError', null)->call('saveLayout');
        $props = $board->fresh()->layout['widgets'][0]['props'];
        $this->assertSame('calls.machine_type', $props['column']);
        $this->assertSame('calls.service_type', $props['series_column']);
        $this->assertSame('horizontal', $props['orientation']);
        $this->assertSame(50, $props['limit']);
    }

    public function test_wizard_rejects_date_grouping_on_text_and_unavailable_tables_without_creating_a_dashboard(): void
    {
        $owner = User::factory()->president()->create();
        Livewire::actingAs($owner)->test(WidgetWizard::class, ['table' => 'service-requests'])
            ->set('dashboardId', null)->set('newDashboardName', 'Invalid chart')->set('title', 'Test')
            ->set('widgetType', 'column_chart')->set('builderProps.column', 'machine_type')
            ->set('builderProps.grouping', 'year')->call('create')->assertHasErrors('builderProps');
        $this->assertDatabaseMissing('dashboards', ['name' => 'Invalid chart']);
        $private = DynamicTable::create(['key' => 'private-data', 'name' => 'Private', 'created_by' => User::factory()->create()->id]);
        Livewire::actingAs($owner)->test(WidgetWizard::class)->set('tableKey', $private->key)
            ->set('title', 'Forbidden')->set('widgetType', 'column_chart')->call('create')->assertHasErrors('tableKey');
    }

    public function test_slicers_filter_existing_product_metrics_regional_charts_and_top_accounts(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());
        foreach (['A', 'B'] as $brand) {
            $account = Account::create(['source_system' => 'test', 'source_record_id' => $brand, 'customer_name' => 'Hospital '.$brand, 'region' => 'NCR']);
            Installation::create(['account_id' => $account->id, 'source_system' => 'test', 'source_record_id' => $brand, 'brand' => $brand, 'device_status' => 'Active']);
        }
        $filters = ['slicers' => ['brand' => ['table_key' => 'installed-products', 'key' => 'brand', 'mode' => 'value', 'values' => ['A']]]];
        $summary = app(ProductDashboardService::class)->summary(null, '12M', $filters);
        $this->assertSame(1, $summary['metrics']['installed']);
        $this->assertSame(1, $summary['regions'][0]['products']);
        $this->assertSame(['Hospital A'], array_column($summary['topAccounts'], 'customer'));
    }

    public function test_personnel_position_is_chartable_while_dynamic_row_order_is_not(): void
    {
        $owner = User::factory()->superadmin()->create();
        $this->actingAs($owner);
        TechnicalPersonnel::create(['source_system' => 'test', 'source_record_id' => 'person-1', 'name' => 'Test engineer', 'position' => 'Engineer', 'region' => 'NCR']);
        $service = app(DashboardColumnService::class);
        $chart = $service->chart(['column' => 'people.position'], ['people' => 'personnel'], []);
        $this->assertSame([['label' => 'Engineer', 'values' => [1.0]]], $chart['points']);
        DynamicTable::create(['key' => 'row-order-test', 'name' => 'Row order test', 'created_by' => $owner->id]);
        $this->assertArrayNotHasKey('position', $service->columns('row-order-test'));
    }
}
