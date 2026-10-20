<?php

namespace Tests\Feature;

use App\Livewire\InstalledProductsTable;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstalledProductsCustomerDropdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_name_column_is_a_dropdown_of_account_names(): void
    {
        Account::create([
            'source_system' => 'manual',
            'source_record_id' => 'alpha',
            'customer_name' => 'Alpha Hospital',
        ]);
        Account::create([
            'source_system' => 'manual',
            'source_record_id' => 'beta',
            'customer_name' => 'Beta Clinic',
        ]);
        Account::create([
            'source_system' => 'monday',
            'source_record_id' => 'account-installed-products',
            'customer_name' => 'monday.com import',
        ]);

        $column = collect((new InstalledProductsTable)->columns())
            ->firstWhere('key', 'account.customer_name');

        $this->assertNotNull($column);
        $this->assertSame('select', $column['type']);
        $this->assertSame(['Alpha Hospital', 'Beta Clinic'], $column['options']);
    }
}
