<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\Dashboard as DashboardModel;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $sourceRecordId, string $name, string $region): Account
    {
        return Account::create([
            'source_system' => 'product_database',
            'source_record_id' => $sourceRecordId,
            'customer_name' => $name,
            'region' => $region,
        ]);
    }

    public function test_home_is_a_product_database_overview(): void
    {
        $ncr = $this->account('acc-1', 'Example Hospital', 'NCR');
        Installation::create([
            'account_id' => $ncr->id,
            'source_system' => 'product_database',
            'source_record_id' => '1-aaaaaaaaaaaaaaaaaaaaaaaa',
            'brand' => 'SYSMEX',
            'machine_type' => 'PORTABLE DEVICE',
            'device_status' => 'Active',
            'warranty_status' => 'Yes',
            'service_contract_status' => 'Yes',
            'installation_date' => now()->subMonths(2),
        ]);
        Installation::create([
            'account_id' => $ncr->id,
            'source_system' => 'product_database',
            'source_record_id' => '2-bbbbbbbbbbbbbbbbbbbbbbbb',
            'brand' => 'SYSMEX',
            'machine_type' => 'FULLY-AUTOMATED',
            'device_status' => 'Pulledout',
            'warranty_status' => 'No',
            'installation_date' => now()->subYear(),
        ]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Product Database')
            ->assertSee('Installed products')
            ->assertSee('Active products')
            ->assertSee('Warranty covered')
            ->assertSee('Service contracts')
            ->assertSee('Leading installed brands')
            ->assertSee('Equipment types')
            ->assertSee('Largest installed accounts')
            ->assertSee('SYSMEX')
            ->assertSee('PORTABLE DEVICE')
            ->assertSee('Example Hospital')
            // Every widget deep-links to the source rows in the Product Database.
            ->assertSee('installed-products?status=Active')
            ->assertSee('installed-products?warranty=covered')
            ->assertSee('installed-products?contract=1')
            ->assertSee('installed-products?pms=missing')
            ->assertSee('installed-products?region=NCR')
            ->assertSee('installed-products?installed=')
            // Operations widgets belong to their own pages, not the home overview.
            ->assertDontSee('Work by status')
            ->assertDontSee('Top TSPs by work logged');
    }

    public function test_region_filter_narrows_the_installed_base(): void
    {
        $ncr = $this->account('acc-ncr', 'NCR Hospital', 'NCR');
        Installation::create([
            'account_id' => $ncr->id,
            'source_system' => 'product_database',
            'source_record_id' => '10-aaaaaaaaaaaaaaaaaaaaaaaa',
            'brand' => 'SYSMEX',
        ]);
        $vis = $this->account('acc-vis', 'Visayas Hospital', 'Visayas');
        Installation::create([
            'account_id' => $vis->id,
            'source_system' => 'product_database',
            'source_record_id' => '11-bbbbbbbbbbbbbbbbbbbbbbbb',
            'brand' => 'TERUMO',
        ]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(Dashboard::class)
            ->assertSee('SYSMEX')
            ->assertSee('TERUMO')
            ->set('region', 'NCR')
            ->assertSee('SYSMEX')
            ->assertDontSee('TERUMO');
    }

    public function test_regional_manager_is_scoped_to_their_region(): void
    {
        $ncr = $this->account('acc-ncr-2', 'NCR Hospital', 'NCR');
        Installation::create([
            'account_id' => $ncr->id,
            'source_system' => 'product_database',
            'source_record_id' => '20-aaaaaaaaaaaaaaaaaaaaaaaa',
            'brand' => 'SYSMEX',
        ]);
        $vis = $this->account('acc-vis-2', 'Visayas Hospital', 'Visayas');
        Installation::create([
            'account_id' => $vis->id,
            'source_system' => 'product_database',
            'source_record_id' => '21-bbbbbbbbbbbbbbbbbbbbbbbb',
            'brand' => 'TERUMO',
        ]);

        // Regional managers see empty Home (no PDB default). Scoping is verified
        // via an owned dashboard that has an installed-products source.
        $user = User::factory()->regionalManager('NCR')->create();
        $dashboard = DashboardModel::create([
            'owner_id' => $user->id,
            'name' => 'Scoped board',
            'layout' => ['version' => 1, 'widgets' => [[
                'id' => 'table-1',
                'type' => 'table',
                'w' => 12,
                'h' => 4,
                'props' => ['label' => 'Top accounts', 'dataset' => 'pdb.top_accounts'],
            ]]],
        ]);
        $dashboard->sources()->create(['table_key' => 'installed-products', 'alias' => 'pdb', 'position' => 0]);

        Livewire::actingAs($user)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->assertSee('Scoped to your region')
            ->assertSee('NCR Hospital')
            ->set('region', 'Visayas')
            ->assertDontSee('Visayas Hospital');
    }

    public function test_home_for_non_superadmin_is_empty_onboarding(): void
    {
        $this->actingAs(User::factory()->regionalManager('NCR')->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('No dashboard yet');
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
