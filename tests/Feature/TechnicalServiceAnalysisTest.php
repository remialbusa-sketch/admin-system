<?php

namespace Tests\Feature;

use App\Livewire\TechnicalReportTable;
use App\Livewire\TechnicalServiceAnalysis;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Technical Service Analysis widgets deep-link to the Technical Reports grid
 * with URL-bound filters — every drill-down lands on exactly the rows behind
 * the number.
 */
class TechnicalServiceAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'TR-1',
            'reference_number' => 'TR-1',
            'customer_name' => 'Alpha Hospital',
            'tsp_name' => 'Alice',
            'brand' => 'SYSMEX',
            'service_status' => 'Completed',
            'service_completed_at' => now()->subDays(2),
            'repair_time_hours' => 2,
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'TR-2',
            'reference_number' => 'TR-2',
            'customer_name' => 'Beta Hospital',
            'tsp_name' => null,
            'brand' => 'TERUMO',
            'service_status' => 'In-Progress',
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'TR-3',
            'reference_number' => 'TR-3',
            'customer_name' => 'Gamma Hospital',
            'tsp_name' => 'Alice',
            'brand' => 'SYSMEX',
            'service_status' => 'Completed',
            'service_completed_at' => now()->subDays(10),
        ]);
        // Real workbook shape: tsp_name is a person ID, the display name is mapped.
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'TR-4',
            'reference_number' => 'TR-4',
            'customer_name' => 'Delta Hospital',
            'tsp_name' => 'person-999',
            'tsp_display_name' => 'Dexter Lim',
            'brand' => 'TERUMO',
            'service_status' => 'Completed',
            'service_completed_at' => now()->subDays(5),
        ]);
    }

    private function grid(array $query)
    {
        return Livewire::actingAs(User::factory()->president()->create())
            ->withQueryParams($query)
            ->test(TechnicalReportTable::class);
    }

    public function test_tsp_query_param_filters_reports(): void
    {
        $this->grid(['tsp' => 'Alice'])
            ->assertSee('Alpha Hospital')
            ->assertSee('Gamma Hospital')
            ->assertDontSee('Beta Hospital');
    }

    public function test_brand_query_param_filters_reports(): void
    {
        $this->grid(['brand' => 'TERUMO'])
            ->assertSee('Beta Hospital')
            ->assertDontSee('Alpha Hospital');
    }

    public function test_customer_query_param_filters_reports(): void
    {
        $this->grid(['customer' => 'Gamma'])
            ->assertSee('Gamma Hospital')
            ->assertDontSee('Alpha Hospital');
    }

    public function test_assigned_query_param_filters_by_tsp_presence(): void
    {
        $this->grid(['assigned' => '1'])
            ->assertSee('Alpha Hospital')
            ->assertDontSee('Beta Hospital');

        $this->grid(['assigned' => '0'])
            ->assertSee('Beta Hospital')
            ->assertDontSee('Alpha Hospital');
    }

    public function test_completed_any_query_param_filters_dated_reports(): void
    {
        $this->grid(['completed' => 'any'])
            ->assertSee('Alpha Hospital')
            ->assertSee('Gamma Hospital')
            ->assertDontSee('Beta Hospital');
    }

    public function test_completed_date_query_param_filters_one_day(): void
    {
        $this->grid(['completed' => now()->subDays(10)->toDateString()])
            ->assertSee('Gamma Hospital')
            ->assertDontSee('Alpha Hospital');
    }

    public function test_completed_range_query_params_filter_a_window(): void
    {
        $this->grid([
            'completed_from' => now()->subDays(3)->toDateString(),
            'completed_to' => now()->toDateString(),
        ])->assertSee('Alpha Hospital')
            ->assertDontSee('Gamma Hospital');
    }

    public function test_drill_down_chips_render_and_clear(): void
    {
        $this->grid(['tsp' => 'Alice'])
            ->assertSee('Drill-down from dashboard')
            ->assertSee('TSP: Alice')
            ->call('clearDrillDown')
            ->assertSet('tsp', null)
            ->assertSee('Beta Hospital');
    }

    public function test_tsp_widget_shows_display_names_not_workbook_ids(): void
    {
        Livewire::actingAs(User::factory()->president()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertSee('Dexter Lim');
    }

    public function test_tsp_drill_chip_resolves_the_display_name(): void
    {
        $this->grid(['tsp' => 'person-999'])
            ->assertSee('TSP: Dexter Lim');
    }

    public function test_grid_shows_both_the_tsp_id_and_the_display_name(): void
    {
        $this->grid([])
            ->assertSee('person-999')
            ->assertSee('Dexter Lim');
    }

    public function test_brand_donut_segments_get_unique_colors(): void
    {
        Livewire::actingAs(User::factory()->president()->create())
            ->test(TechnicalServiceAnalysis::class)
            // Two brands → two distinct categorical colors (not one flat tone).
            ->assertSee('var(--chart-1)')
            ->assertSee('var(--chart-2)');
    }

    public function test_analysis_page_ships_drill_links_and_period_options(): void
    {
        Livewire::actingAs(User::factory()->president()->create())
            ->test(TechnicalServiceAnalysis::class)
            ->assertOk()
            ->assertSee('Technical Reports')
            ->assertSee('Reports completed')
            ->assertSee('Top TSPs by reports')
            ->assertSee('Most serviced brands')
            ->assertSee('technical-reports?completed=')
            ->assertSee('technical-reports?tsp=Alice')
            ->assertSee('technical-reports?brand=SYSMEX')
            ->assertSee('technical-reports?status=Completed')
            ->assertSee('Last 7D')
            ->assertSee('Last 90D')
            // Operations-not-in-scope checks: TSA stays report-focused.
            ->assertDontSee('Installed products')
            ->assertDontSee('Warranty covered');
    }

    public function test_period_selector_recomputes_the_window(): void
    {
        Livewire::actingAs(User::factory()->president()->create())
            ->test(TechnicalServiceAnalysis::class)
            // 30D uses weekly buckets (range links); TR-3 (10 days old) is in one.
            ->assertSee('completed_from=')
            // 7D switches to per-day buckets: TR-1 (2 days ago) keeps its day
            // point, TR-3 (10 days ago) has no day link anymore.
            ->set('period', '7D')
            ->assertSee('completed='.now()->subDays(2)->toDateString())
            ->assertDontSee('completed='.now()->subDays(10)->toDateString());
    }
}
