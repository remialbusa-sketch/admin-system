<?php

namespace Tests\Feature;

use App\Models\TechnicalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairTechnicianNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_is_dry_by_default_idempotent_and_keeps_unrecoverable_raw_source(): void
    {
        $report = TechnicalReport::create(['source_system' => 'executive_dashboard', 'source_record_id' => '1', 'reference_number' => '1', 'tsp_name' => 'Fidel Carino', 'tsp_display_name' => 'https://example.com/signature.png']);
        $unassigned = TechnicalReport::create(['source_system' => 'executive_dashboard', 'source_record_id' => '2', 'reference_number' => '2', 'tsp_name' => 'https://example.com/signature.png', 'raw_data' => ['tsp' => 'https://example.com/signature.png', 'tsp_name' => null]]);
        $valid = TechnicalReport::create(['source_system' => 'executive_dashboard', 'source_record_id' => '3', 'reference_number' => '3', 'tsp_name' => 'person-123', 'tsp_display_name' => 'Fidel Carino']);
        $this->artisan('monday:repair-technicians')->expectsOutputToContain('Dry run: 2 reports')->assertSuccessful();
        $this->assertSame('https://example.com/signature.png', $report->fresh()->tsp_display_name);
        $this->artisan('monday:repair-technicians --apply')->expectsOutputToContain('1 have no recoverable technician')->assertSuccessful();
        $this->assertSame('Fidel Carino', $report->fresh()->tsp_display_name);
        $this->assertNull($unassigned->fresh()->tsp_name);
        $this->assertSame('https://example.com/signature.png', $unassigned->fresh()->raw_data['tsp']);
        $this->assertSame('person-123', $valid->fresh()->tsp_name);
        $this->artisan('monday:repair-technicians --apply')->expectsOutputToContain('Applied: 0 reports')->assertSuccessful();
    }
}
