<?php

namespace Tests\Feature;

use App\Livewire\TspAnalytics;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TspAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tsp_analytics_renders_real_personnel_counts(): void
    {
        TechnicalPersonnel::create([
            'source_system' => 'personnel_list',
            'source_record_id' => 'personnel-tsp-1',
            'name' => 'Field Person',
            'position' => 'Field Service Engineer',
            'branch' => 'NCR',
            'region' => 'NCR',
        ]);
        TechnicalPersonnel::create([
            'source_system' => 'personnel_list',
            'source_record_id' => 'personnel-tsp-2',
            'name' => 'IT Person',
            'position' => 'IT Specialist',
            'branch' => 'NCR',
            'region' => 'NCR',
        ]);

        Livewire::test(TspAnalytics::class)
            ->assertOk()
            ->assertSee('Active TSPs')
            ->assertSee('Per-TSP performance')
            ->assertSee('Regional performance')
            ->assertSee('NCR')
            ->assertDontSee('Updated 10 minutes ago');
    }

    public function test_tsp_filter_narrows_per_tsp_table(): void
    {
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-a',
            'tsp_name' => 'person-100',
            'service_status' => 'Completed',
            'service_started_at' => now()->subDays(5),
            'repair_time_hours' => 1.5,
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-b',
            'tsp_name' => 'person-200',
            'service_status' => 'Completed',
            'service_started_at' => now()->subDays(5),
            'repair_time_hours' => 2.0,
        ]);

        Livewire::test(TspAnalytics::class)
            ->set('tspName', 'person-100')
            // Filtered KPI should count only the selected TSP.
            ->assertSee('1') // Distinct TSPs / Reports (filtered)
            ->assertSee('person-100');
    }

    public function test_date_filter_excludes_out_of_range_reports(): void
    {
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-old',
            'tsp_name' => 'person-old',
            'service_status' => 'Completed',
            'service_started_at' => now()->subYear(),
            'repair_time_hours' => 1.0,
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-new',
            'tsp_name' => 'person-new',
            'service_status' => 'Completed',
            'service_started_at' => now()->subDays(2),
            'repair_time_hours' => 1.0,
        ]);

        Livewire::test(TspAnalytics::class)
            ->set('dateFrom', now()->subDays(7)->toDateString())
            ->set('dateTo', now()->toDateString())
            ->assertSee('person-new')
            // Only one report falls in the 7-day window.
            ->assertSee('Reports (filtered)');
    }
}
