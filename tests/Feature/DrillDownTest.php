<?php

namespace Tests\Feature;

use App\Livewire\InstalledProductsTable;
use App\Models\Account;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dashboard widgets deep-link to the Product Database grid with URL-bound
 * filters — every drill-down lands on exactly the rows behind the number.
 */
class DrillDownTest extends TestCase
{
    use RefreshDatabase;

    private Account $ncr;

    private Account $vis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ncr = Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'acc-ncr',
            'customer_name' => 'Alpha Hospital',
            'region' => 'NCR',
        ]);
        $this->vis = Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'acc-vis',
            'customer_name' => 'Beta Hospital',
            'region' => 'Visayas',
        ]);

        Installation::create([
            'account_id' => $this->ncr->id,
            'source_system' => 'product_database',
            'source_record_id' => '1-aaaaaaaaaaaaaaaaaaaaaaaa',
            'brand' => 'SYSMEX',
            'machine_type' => 'PORTABLE DEVICE',
            'device_status' => 'Active',
            'warranty_status' => 'Yes',
            'pms_frequency' => null,
            'installation_date' => '2026-01-15',
        ]);
        Installation::create([
            'account_id' => $this->vis->id,
            'source_system' => 'product_database',
            'source_record_id' => '2-bbbbbbbbbbbbbbbbbbbbbbbb',
            'brand' => 'TERUMO',
            'machine_type' => 'FULLY-AUTOMATED',
            'device_status' => 'Pulledout',
            'warranty_status' => 'No',
            'pms_frequency' => 'Annual',
            'installation_date' => '2025-06-10',
            'warranty_end_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    private function grid(array $query)
    {
        return Livewire::actingAs(User::factory()->president()->create())
            ->withQueryParams($query)
            ->test(InstalledProductsTable::class);
    }

    public function test_status_query_param_filters_device_status(): void
    {
        $this->grid(['status' => 'Pulledout'])
            ->assertSee('TERUMO')
            ->assertDontSee('SYSMEX');
    }

    public function test_brand_query_param_filters_brand(): void
    {
        $this->grid(['brand' => 'SYSMEX'])
            ->assertSee('SYSMEX')
            ->assertDontSee('TERUMO');
    }

    public function test_machine_type_query_param_filters_machine_type(): void
    {
        $this->grid(['machine_type' => 'PORTABLE DEVICE'])
            ->assertSee('SYSMEX')
            ->assertDontSee('TERUMO');
    }

    public function test_region_query_param_scopes_to_the_region(): void
    {
        $this->grid(['region' => 'Visayas'])
            ->assertSee('TERUMO')
            ->assertDontSee('SYSMEX');
    }

    public function test_customer_query_param_scopes_to_the_account(): void
    {
        $this->grid(['customer' => 'Beta Hospital'])
            ->assertSee('TERUMO')
            ->assertDontSee('Alpha Hospital');
    }

    public function test_warranty_expiring_query_param_filters_by_end_date(): void
    {
        $this->grid(['warranty' => 'expiring_90d'])
            ->assertSee('TERUMO')
            ->assertDontSee('SYSMEX');
    }

    public function test_pms_missing_query_param_filters_records_without_pms(): void
    {
        $this->grid(['pms' => 'missing'])
            ->assertSee('SYSMEX')
            ->assertDontSee('TERUMO');
    }

    public function test_installed_month_query_param_filters_installation_date(): void
    {
        $this->grid(['installed' => '2026-01'])
            ->assertSee('SYSMEX')
            ->assertDontSee('TERUMO');
    }

    public function test_drill_down_chips_render_and_clear(): void
    {
        $component = $this->grid(['brand' => 'SYSMEX']);

        $component->assertSee('Drill-down from dashboard')
            ->assertSee('Brand: SYSMEX')
            ->call('clearDrillDown')
            ->assertSet('brand', null)
            // Both rows visible again after clearing.
            ->assertSee('TERUMO')
            ->assertSee('SYSMEX');
    }

    public function test_deep_linked_status_survives_mount(): void
    {
        // The mount() default must not clobber a deep-linked ?status=…
        $this->grid(['status' => 'Pulledout'])
            ->assertSet('statusFilter', 'Pulledout');
    }
}
