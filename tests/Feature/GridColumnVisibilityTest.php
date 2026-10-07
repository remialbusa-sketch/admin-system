<?php

namespace Tests\Feature;

use App\Livewire\InstalledProductsTable;
use App\Livewire\ServiceRequestTable;
use App\Models\Account;
use App\Models\CustomTableColumn;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The grid renders core model columns plus custom columns. The monday sync
 * writes only the title field into core columns, so most core headers used
 * to render permanently empty next to their filled custom twins. Rules
 * (2026-10-07): "Service Request #" reads service_request_number (what both
 * import paths actually write), core columns with no values anywhere hide
 * once the table has rows, and custom columns / empty tables never hide.
 */
class GridColumnVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    public function test_service_request_number_renders_instead_of_the_code_field(): void
    {
        ServiceRequest::create([
            'source_system' => 'monday:service-requests',
            'source_record_id' => '777',
            'service_request_number' => 'SN-777',
            'service_request_code' => 'SR-777',
        ]);

        Livewire::actingAs($this->superadmin())
            ->test(ServiceRequestTable::class)
            ->assertSee('SN-777')
            ->assertDontSee('SR-777');
    }

    public function test_always_empty_core_columns_hide_once_the_table_has_rows(): void
    {
        ServiceRequest::create([
            'source_system' => 'monday:service-requests',
            'source_record_id' => '778',
            'service_request_number' => 'SN-778',
        ]);

        Livewire::actingAs($this->superadmin())
            ->test(ServiceRequestTable::class)
            ->assertSee('Service Request #')
            ->assertDontSee('Group Status')
            ->assertDontSee('TSP Assignment');
    }

    public function test_an_empty_table_still_renders_every_core_column(): void
    {
        Livewire::actingAs($this->superadmin())
            ->test(ServiceRequestTable::class)
            ->assertSee('Group Status')
            ->assertSee('Service Request #');
    }

    public function test_custom_columns_stay_visible_even_when_no_cell_is_filled(): void
    {
        CustomTableColumn::create([
            'table_key' => 'service-requests',
            'name' => 'ZZZ Empty Custom',
            'type' => 'text',
            'position' => 99,
        ]);

        ServiceRequest::create([
            'source_system' => 'monday:service-requests',
            'source_record_id' => '779',
            'service_request_number' => 'SN-779',
        ]);

        Livewire::actingAs($this->superadmin())
            ->test(ServiceRequestTable::class)
            ->assertSee('ZZZ Empty Custom');
    }

    public function test_relation_core_columns_hide_when_the_related_row_is_blank(): void
    {
        $account = Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'account-'.uniqid(),
            'customer_name' => 'Visible Hospital',
            // customer_address deliberately blank
        ]);
        Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => 'product-'.uniqid(),
            'device_description' => 'Analyzer',
        ]);

        Livewire::actingAs($this->superadmin())
            ->test(InstalledProductsTable::class)
            ->assertSee('Customer Name')
            ->assertDontSee('Customer Address');
    }
}
