<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\HistoricalTsmsReport;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PresidentDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_consolidated_home_shows_live_product_personnel_and_history_metrics(): void
    {
        $account = Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'account-1',
            'customer_name' => 'Example Hospital',
            'branch' => 'NCR',
            'region' => 'NCR',
        ]);
        Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => 'product-1',
            'brand' => 'SYSMEX',
            'device_status' => 'Active',
            'warranty_status' => 'Yes',
        ]);
        TechnicalPersonnel::create([
            'source_system' => 'personnel_list',
            'source_record_id' => 'personnel-1',
            'name' => 'Field Engineer',
            'position' => 'Field Service Engineer',
            'region' => 'NCR',
        ]);
        ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-1',
            'service_request_number' => 'SR-1',
            'region' => 'NCR',
            'group_status' => 'Completed',
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'report-1',
            'reference_number' => 'TR-1',
            'service_status' => 'Completed',
            'tsp_name' => 'Field Engineer',
            'repair_time_hours' => 2,
        ]);
        HistoricalTsmsReport::create([
            'source_system' => 'historical_tsms',
            'source_record_id' => 'history-1',
            'csr_number' => 'CSR-1',
            'status' => 'Completed',
            'service_type' => 'Repair',
            'tsp_name' => 'Legacy Engineer',
            'response_timestamp' => now()->subYear(),
        ]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Home')
            ->assertSee('Product database')
            ->assertSee('Technical personnel')
            ->assertSee('Historical TSMS')
            ->assertSee('Outcome share')
            ->assertSee('Work by status')
            ->assertSee('Leading installed brands')
            ->assertSee('Top TSPs by work logged')
            ->assertSee('SYSMEX')             // product brand from Product Database
            ->assertSee('Field Engineer');    // service/field TSP workload
    }

    public function test_consolidated_home_region_filter_narrows_scope(): void
    {
        $accountNcr = Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'account-ncr',
            'customer_name' => 'NCR Hospital',
            'region' => 'NCR',
        ]);
        Installation::create([
            'account_id' => $accountNcr->id,
            'source_system' => 'product_database',
            'source_record_id' => 'product-ncr',
            'brand' => 'SYSMEX',
        ]);
        $accountVis = Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'account-vis',
            'customer_name' => 'Visayas Hospital',
            'region' => 'Visayas',
        ]);
        Installation::create([
            'account_id' => $accountVis->id,
            'source_system' => 'product_database',
            'source_record_id' => 'product-vis',
            'brand' => 'TERUMO',
        ]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(Dashboard::class)
            ->assertSee('Product database')
            ->assertSee('TERUMO')
            ->set('region', 'NCR')
            ->assertSee('SYSMEX')
            ->assertDontSee('TERUMO');
    }

    public function test_president_route_redirects_to_consolidated_home(): void
    {
        $this->actingAs(User::factory()->regionalManager('NCR')->create())
            ->get('/president')
            ->assertRedirect('/dashboard');
    }

    public function test_regional_manager_can_access_consolidated_home(): void
    {
        $this->actingAs(User::factory()->regionalManager('NCR')->create())
            ->get('/dashboard')
            ->assertOk();
    }
}