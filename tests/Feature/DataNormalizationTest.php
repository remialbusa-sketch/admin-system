<?php

namespace Tests\Feature;

use App\Services\SourceWorkbookImportService;
use App\Models\ServiceRequest;
use App\Models\TechnicalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SourceWorkbookImportService
    {
        return app(SourceWorkbookImportService::class);
    }

    public function test_region_for_branch_maps_all_observed_forms_to_the_four_regions(): void
    {
        $cases = [
            // branch codes from Service Requests
            'NCR' => 'NCR',
            'CEB' => 'Visayas',
            'CDO' => 'Mindanao',
            'DAV' => 'Mindanao',
            'ZAM' => 'Mindanao',
            'NLR1' => 'North Luzon',
            'NLR2' => 'North Luzon',
            'NLR3' => 'North Luzon',
            'BAC' => 'Visayas',
            'ILO' => 'Visayas',
            'TAC' => 'Visayas',
            // descriptive branch names
            'Cebu' => 'Visayas',
            'Ilo-Ilo' => 'Visayas',
            'Davao' => 'Mindanao',
            'North Luzon' => 'North Luzon',
            'Visayas' => 'Visayas',
            'Mindanao' => 'Mindanao',
            // South Luzon resolves to NCR per source convention
            'South Luzon' => 'NCR',
            'SL' => 'NCR',
            // raw region text with roman numerals and parenthetical names
            'Region III (Central Luzon)' => 'North Luzon',
            'Region VII (Central Visayas)' => 'Visayas',
            'Region X (Northern Mindanao)' => 'Mindanao',
            'Region IV-A (CALABARZON)' => 'NCR',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $this->service()->regionForBranch($input), "regionForBranch('{$input}')");
        }
    }

    public function test_region_for_branch_returns_null_for_unassignable_input(): void
    {
        $this->assertNull($this->service()->regionForBranch(''));
        $this->assertNull($this->service()->regionForBranch(null));
    }

    public function test_normalize_status_canonicalizes_raw_variants(): void
    {
        $cases = [
            'Completed' => 'Completed',
            'COMPLETED' => 'Completed',
            'Resolved' => 'Completed',
            'closed' => 'Completed',
            'In-Progress' => 'In-Progress',
            'IN PROGRESS' => 'In-Progress',
            'Open' => 'Open',
            'OPEN' => 'Open',
            'Rejected' => 'Rejected',
            'For Continuation' => 'For Continuation',
            'For Escalation' => 'For Escalation',
            'Completed, In-Progress' => 'Completed', // multi-value noise strips to first
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $this->service()->normalizeStatus($input), "normalizeStatus({$input})");
        }
        $this->assertNull($this->service()->normalizeStatus(''));
        $this->assertNull($this->service()->normalizeStatus(null));
    }

    public function test_service_request_region_derived_from_branch_over_raw_region(): void
    {
        $request = ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-1',
            'service_request_number' => 'SR-1',
            'branch' => 'CEB',
            'region' => 'Region III (Central Luzon)', // raw region is wrong; branch wins
        ]);
        // Re-run the canonicalization that the importer applies
        $svc = $this->service();
        $request->region = $svc->regionForBranch($request->branch) ?? $svc->regionForBranch($request->region);
        $this->assertSame('Visayas', $request->region);
    }

    public function test_technical_report_group_status_prefers_service_status(): void
    {
        $report = TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'REF-1',
            'reference_number' => 'REF-1',
            'service_status' => 'OPEN',
            'ticket_status' => 'Completed',
        ]);
        $report->group_status = $report->service_status
            ? app(SourceWorkbookImportService::class)->normalizeStatus($report->service_status)
            : app(SourceWorkbookImportService::class)->normalizeStatus($report->ticket_status);
        $this->assertSame('Open', $report->group_status);
    }
}