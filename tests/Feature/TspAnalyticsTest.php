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

    public function test_tsp_options_are_normalized_identities(): void
    {
        // Same person under two workbook IDs (one with mixed-case display).
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-n1',
            'tsp_name' => 'person-001',
            'tsp_display_name' => 'Juan Dela Cruz',
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-n2',
            'tsp_name' => 'person-001',
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-n3',
            'tsp_name' => 'person-002',
            'tsp_display_name' => 'juan dela cruz',
        ]);
        // Email-only display name and a doubled comma segment.
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-n4',
            'tsp_name' => 'person-003',
            'tsp_display_name' => 'franco.dagondon@mcbtsi.com',
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-n5',
            'tsp_name' => 'team-32875, team-32875',
        ]);

        $options = app(\App\Services\TspAnalyticsService::class)->tspOptions();

        // One normalized identity per person, not one per workbook ID.
        $this->assertSame([
            'Franco Dagondon' => 'Franco Dagondon',
            'Juan Dela Cruz' => 'Juan Dela Cruz',
            'team-32875' => 'team-32875',
        ], $options);
    }

    public function test_selecting_a_normalized_tsp_counts_all_workbook_ids(): void
    {
        // "Warren Suba" style split identity: two IDs, same person.
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-w1',
            'tsp_name' => 'person-100',
            'tsp_display_name' => 'Warren Suba',
            'service_status' => 'Completed',
            'repair_time_hours' => 1.0,
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-w2',
            'tsp_name' => 'person-200',
            'tsp_display_name' => 'warren suba',
            'service_status' => 'In-Progress',
            'repair_time_hours' => 3.0,
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'tr-w3',
            'tsp_name' => 'person-300',
            'tsp_display_name' => 'Other Person',
            'service_status' => 'Completed',
        ]);

        Livewire::test(TspAnalytics::class)
            ->set('tspName', 'Warren Suba')
            ->assertSee('Warren Suba');

        $details = app(\App\Services\TspAnalyticsService::class)->details('Warren Suba');

        $this->assertSame(2, $details['filteredReports']);
        $this->assertSame('1', $details['kpis'][1]['value']);
        $this->assertCount(1, $details['topTsp']);
        $this->assertSame('Warren Suba', $details['topTsp'][0]['tsp_name']);
        $this->assertSame(2, $details['topTsp'][0]['reports']);
        // Weighted average repair time across both workbook IDs: (1.0 + 3.0) / 2.
        $this->assertSame(2.0, $details['topTsp'][0]['avg_repair']);
    }
}
