<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\HistoricalTsmsReport;
use App\Models\ImportBatch;
use App\Models\ImportFailure;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceDomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_domains_preserve_identity_and_relationships(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::create([
            'source_system' => 'executive_dashboard',
            'source_name' => 'MCBTSI_Executive_Dashboard_Updated.xlsx',
            'source_sheet' => 'Service Requests',
            'status' => 'completed',
            'run_by' => $user->id,
        ]);

        $account = Account::create([
            'import_batch_id' => $batch->id,
            'source_system' => 'product_database',
            'source_record_id' => 'product-row-1',
            'source_hash' => hash('sha256', 'product-row-1'),
            'customer_name' => 'Example Hospital',
            'region' => 'NCR',
        ]);

        $installation = Installation::create([
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'source_system' => 'product_database',
            'source_record_id' => 'product-row-1-installation-1',
            'brand' => 'Example',
            'serial_number' => 'SN-001',
        ]);

        $request = ServiceRequest::create([
            'import_batch_id' => $batch->id,
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-001',
            'service_request_number' => 'SR-001',
            'group_status' => 'Completed',
            'ticket_status' => 'Resolved',
        ]);

        $report = TechnicalReport::create([
            'service_request_id' => $request->id,
            'import_batch_id' => $batch->id,
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'TR-001',
            'reference_number' => 'TR-001',
            'service_request_number' => 'SR-001',
            'repair_time_hours' => 1.5,
        ]);

        $historical = HistoricalTsmsReport::create([
            'import_batch_id' => $batch->id,
            'source_system' => 'historical_tsms',
            'source_record_id' => '2026-08-20T10:00:00-row-1',
            'source_hash' => hash('sha256', 'historical-row-1'),
            'csr_number' => 'CSR-001',
            'tsr_number' => 'TSR-001',
        ]);

        $failure = ImportFailure::create([
            'import_batch_id' => $batch->id,
            'row_number' => 12,
            'source_record_id' => 'SR-INVALID',
            'error_message' => 'Invalid status value.',
            'raw_data' => ['Status' => '???'],
        ]);

        $this->assertTrue($account->installations->contains($installation));
        $this->assertTrue($request->technicalReports->contains($report));
        $this->assertTrue($batch->failures->contains($failure));
        $this->assertSame('historical_tsms', $historical->source_system);
        $this->assertSame('product-row-1', $account->source_record_id);
    }

    public function test_product_import_maps_the_approved_pdb_fields_and_leaves_manual_fields_blank(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdb-').'.csv';

        file_put_contents($path, implode(PHP_EOL, [
            'No.,CUSTOMER - NAME,CUSTOMER - ADDRESS,BRANCH,DEVICE DESCRIPTION,BRAND,SERIAL NUMBER,BU No.,SYSTEM TYPE,INSTALLATION DATE,PULLED OUT DATE,DEVICE OWNERSHIP,DEVICE STATUS,DEAL TYPE,WARRANTY STATUS,WARRANTY PERIOD',
            '1,Example Hospital,123 Main St,NCR,Analyzer,SYSMEX,SN-001,IVD-BU01,Stand Alone,2024-01-15,,Customer,Active,Purchased,Yes,2',
        ]));

        try {
            $batch = app(\App\Services\SourceWorkbookImportService::class)->importProductDatabase($path);
            $installation = Installation::query()->firstOrFail();

            $this->assertSame('completed', $batch->status);
            $this->assertSame('Stand Alone', $installation->equipment_type);
            $this->assertSame('IVD-BU01', $installation->bu_no);
            $this->assertSame('Purchased', $installation->deal_type);
            $this->assertSame('Yes', $installation->warranty_status);
            $this->assertSame(2, $installation->warranty_period_years);
            $this->assertNull($installation->pms_frequency);
            $this->assertNull($installation->tsp_in_charge);
            $this->assertSame('Example Hospital', $installation->raw_data['customer_name']);
        } finally {
            @unlink($path);
        }
    }

    public function test_source_identity_is_unique_per_source_system(): void
    {
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'duplicate-1',
        ]);

        Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'duplicate-1',
        ]);
    }
}
