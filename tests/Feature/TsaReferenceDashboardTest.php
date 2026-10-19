<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\TechnicalServiceAnalysis;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Services\DashboardColumnService;
use App\Support\Dashboard\TechnicalServiceReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TsaReferenceDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regional_viewer_without_an_assigned_region_does_not_receive_all_report_data(): void
    {
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'restricted']);
        Livewire::actingAs(User::factory()->create(['role' => UserRole::RegionalManager, 'region' => null]))
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('metrics', fn ($metrics) => $metrics['reports_total'] === 0)
            ->assertViewHas('tsaGrid', fn ($grid) => collect($grid['widgets'])->firstWhere('id', 'tsa-calls-month')['data']['points'] === []);
    }

    public function test_reference_chart_retains_more_than_100_models_and_20_service_types(): void
    {
        $column = CustomTableColumn::create(['table_key' => 'technical-reports', 'name' => 'Service Type', 'type' => 'text', 'position' => 1]);
        foreach (range(1, 125) as $i) {
            $row = TechnicalReport::create(['source_system' => 'test', 'source_record_id' => (string) $i, 'machine_type' => 'Model '.$i]);
            $type = 'Service '.($i % 25);
            CustomTableColumnValue::create(['custom_column_id' => $column->id, 'row_id' => $row->id, 'value' => ['text' => $type], 'value_text' => $type]);
        }
        $this->actingAs(User::factory()->superadmin()->create());
        $layout = TechnicalServiceReference::layout();
        $props = collect($layout['widgets'])->firstWhere('id', 'tsa-service-model')['props'];
        $chart = app(DashboardColumnService::class)->chart($props, ['tr' => 'technical-reports'], []);
        $this->assertCount(125, $chart['points']);
        $this->assertCount(25, $chart['series']);
        $this->assertSame(125.0, array_sum(array_map(fn ($point) => array_sum($point['values']), $chart['points'])));
        $this->assertFalse($chart['truncated'] || $chart['seriesTruncated']);
    }

    public function test_reference_filters_and_charts_are_applied_to_the_core_dashboard_and_share_one_population(): void
    {
        $column = CustomTableColumn::create(['table_key' => 'technical-reports', 'name' => 'Type of Request', 'type' => 'text', 'position' => 1]);
        foreach ([['2025-01-05', 'XN', 'Repair'], ['2025-02-05', 'XN', 'Installation'], ['2024-01-05', 'XP', 'Repair']] as $i => [$date, $model, $service]) {
            $row = TechnicalReport::create(['source_system' => 'test', 'source_record_id' => (string) $i, 'machine_type' => $model, 'service_started_at' => $date, 'service_completed_at' => $date]);
            CustomTableColumnValue::create(['custom_column_id' => $column->id, 'row_id' => $row->id, 'value' => ['text' => $service], 'value_text' => $service]);
        }
        TechnicalReport::create(['source_system' => 'test', 'source_record_id' => 'archived'])->forceFill(['archived_at' => now()])->save();
        $page = Livewire::actingAs(User::factory()->superadmin()->create())->test(TechnicalServiceAnalysis::class)
            ->assertSee('Total Calls/Month')->assertSee('Service Type by Model')->assertSee('Service Type in Total')
            ->assertSee('Year')->assertSee('Month')->assertSee('Model')->assertSee('Service Type');
        $page->call('toggleSlicerValue', 'tsa-year', '2025')->call('toggleSlicerValue', 'tsa-month', '01');
        $page->assertViewHas('metrics', fn ($metrics) => $metrics['reports_total'] === 1);
        $page->assertViewHas('tsaGrid', function ($grid): bool {
            $charts = collect($grid['widgets'])->keyBy('id');
            $this->assertSame([['label' => '2025-01', 'values' => [1.0]]], $charts['tsa-calls-month']['data']['points']);
            $this->assertSame([['label' => 'XN', 'values' => [1.0]]], $charts['tsa-service-model']['data']['points']);

            return true;
        });
        $page->call('toggleSlicerValue', 'tsa-service', 'Installation')->assertViewHas('metrics', fn ($m) => $m['reports_total'] === 0);
        $page->call('clearAnalysisFilters')->assertViewHas('metrics', fn ($m) => $m['reports_total'] === 3);
        $page->call('toggleSlicerValue', 'tsa-model', 'XN')->assertViewHas('metrics', fn ($m) => $m['reports_total'] === 2);
        $page->set('analysisColumns.model', 'brand')->assertSet('slicerSelections', [])
            ->assertViewHas('metrics', fn ($m) => $m['reports_total'] === 3);
        $page->set('analysisColumns.model', 'machine_type); DROP TABLE technical_reports;')
            ->assertViewHas('tsaGrid', fn ($g) => collect($g['widgets'])->firstWhere('id', 'tsa-model')['props']['column'] === 'tr.machine_type');
    }
}
