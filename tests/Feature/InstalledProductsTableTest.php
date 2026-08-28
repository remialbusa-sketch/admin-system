<?php

namespace Tests\Feature;

use App\Livewire\InstalledProductsTable;
use App\Models\Account;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InstalledProductsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_installed_products_table_shows_only_the_approved_pdb_fields(): void
    {
        $user = User::factory()->president()->create();
        $installation = $this->installation();

        Livewire::actingAs($user)
            ->test(InstalledProductsTable::class)
            ->assertSee('Product Database')
            ->assertSee('Customer Name')
            ->assertSee('Equipment Type')
            ->assertSee('PMS Frequency')
            ->assertSee('TSP In-charge')
            ->assertSee($installation->serial_number)
            ->assertDontSee('Annual BU Charge');
    }

    public function test_only_a_superadmin_can_edit_pdb_fields(): void
    {
        $manager = User::factory()->president()->create();
        $superadmin = User::factory()->superadmin()->create();
        $installation = $this->installation();

        Livewire::actingAs($manager)
            ->test(InstalledProductsTable::class)
            ->call('updateField', $installation->id, 'pms_frequency', 'Monthly')
            ->assertForbidden();

        Livewire::actingAs($superadmin)
            ->test(InstalledProductsTable::class)
            ->call('updateField', $installation->id, 'pms_frequency', 'Monthly')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('installations', [
            'id' => $installation->id,
            'pms_frequency' => 'Monthly',
        ]);
    }

    private function installation(): Installation
    {
        $account = Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'account-'.uniqid(),
            'customer_name' => 'Example Hospital',
            'branch' => 'NCR',
        ]);

        return Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => 'product-'.uniqid(),
            'device_description' => 'Analyzer',
            'brand' => 'SYSMEX',
            'serial_number' => 'SN-001',
            'equipment_type' => 'Stand Alone',
            'raw_data' => ['annual_bu_charge' => '70000'],
        ]);
    }
}