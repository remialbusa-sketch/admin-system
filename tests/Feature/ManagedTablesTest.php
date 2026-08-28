<?php

namespace Tests\Feature;

use App\Livewire\InstalledProductsTable;
use App\Livewire\ServiceRequestTable;
use App\Livewire\TechnicalReportTable;
use App\Livewire\HistoricalTsmsTable;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\TechnicalReport;
use App\Models\HistoricalTsmsReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManagedTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_installed_products_table_shows_only_approved_pdb_fields(): void
    {
        $account = \App\Models\Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'account-'.uniqid(),
            'customer_name' => 'Example Hospital',
            'branch' => 'NCR',
        ]);

        $installation = Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => 'product-'.uniqid(),
            'device_description' => 'Analyzer',
            'brand' => 'SYSMEX',
            'serial_number' => 'SN-001',
            'equipment_type' => 'Stand Alone',
            'raw_data' => ['annual_bu_charge' => '70000'],
        ]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(InstalledProductsTable::class)
            ->assertSee('Product Database')
            ->assertSee('Customer Name')
            ->assertSee('Equipment Type')
            ->assertSee('PMS Frequency')
            ->assertSee('TSP In-charge')
            ->assertSee($installation->serial_number)
            ->assertDontSee('Annual BU Charge');
    }

    public function test_only_superadmin_can_edit_installed_products(): void
    {
        $account = \App\Models\Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'account-'.uniqid(),
            'customer_name' => 'Example Hospital',
        ]);
        $installation = Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => 'product-'.uniqid(),
            'serial_number' => 'SN-002',
        ]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(InstalledProductsTable::class)
            ->call('updateField', $installation->id, 'pms_frequency', 'Monthly')
            ->assertForbidden();

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(InstalledProductsTable::class)
            ->call('updateField', $installation->id, 'pms_frequency', 'Monthly')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('installations', [
            'id' => $installation->id,
            'pms_frequency' => 'Monthly',
        ]);
    }

    public function test_service_request_table_renders_rows_for_superadmin_only_editing(): void
    {
        ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-'.uniqid(),
            'service_request_code' => 'SR-9001',
            'customer_name' => 'Test Customer',
        ]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(ServiceRequestTable::class)
            ->assertSee('Service Requests')
            ->assertSee('SR-9001')
            ->assertSee('Test Customer');
    }

    public function test_technical_report_table_renders_rows(): void
    {
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'REF-'.uniqid(),
            'reference_number' => 'REF-7001',
            'customer_name' => 'Report Customer',
        ]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(TechnicalReportTable::class)
            ->assertSee('Technical Reports')
            ->assertSee('REF-7001');
    }

    public function test_history_report_table_renders_rows(): void
    {
        HistoricalTsmsReport::create([
            'source_system' => 'historical_tsms',
            'source_record_id' => 'CSR-'.uniqid(),
            'csr_number' => 'CSR-5001',
            'account_name' => 'History Account',
        ]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(HistoricalTsmsTable::class)
            ->assertSee('History Reports')
            ->assertSee('CSR-5001');
    }
}
