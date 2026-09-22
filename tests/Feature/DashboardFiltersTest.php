<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $recordId, string $name, string $region, string $branch): Account
    {
        return Account::create([
            'source_system' => 'product_database',
            'source_record_id' => $recordId,
            'customer_name' => $name,
            'region' => $region,
            'branch' => $branch,
        ]);
    }

    private function installation(Account $account, string $recordId, array $attributes = []): Installation
    {
        return Installation::create(array_merge([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => $recordId,
            'brand' => 'SYSMEX',
            'device_status' => 'Active',
            'installation_date' => now()->subMonths(2),
        ], $attributes));
    }

    /**
     * Two branches that differ on every filterable dimension: branch, status,
     * and installation date. Filtering to one dimension must drop the other
     * row from every widget.
     */
    private function seedTwoBranches(): void
    {
        $makati = $this->account('acc-makati', 'Makati Hospital', 'NCR', 'Makati');
        $this->installation($makati, '1-aaaaaaaaaaaaaaaaaaaaaaaa', [
            'brand' => 'SYSMEX',
            'device_status' => 'Active',
            'installation_date' => now()->subMonths(2),
        ]);

        $cebu = $this->account('acc-cebu', 'Cebu Hospital', 'Visayas', 'Cebu');
        $this->installation($cebu, '2-bbbbbbbbbbbbbbbbbbbbbbbb', [
            'brand' => 'TERUMO',
            'device_status' => 'Pulledout',
            'installation_date' => now()->subYear(),
        ]);
    }

    public function test_branch_filter_narrows_every_widget(): void
    {
        $this->seedTwoBranches();

        Livewire::actingAs(User::factory()->president()->create())
            ->test(Dashboard::class)
            ->assertDontSee('Clear filters')
            ->assertSee('Makati')
            ->assertSee('Cebu')
            ->assertSee('TERUMO')
            ->set('branchFilter', 'Makati')
            ->assertSee('Clear filters')
            ->assertSee('SYSMEX')
            ->assertDontSee('TERUMO')
            ->assertDontSee('Cebu Hospital')
            ->call('clearFilters')
            ->assertDontSee('Clear filters')
            ->assertSee('TERUMO');
    }

    public function test_status_filter_narrows_every_widget(): void
    {
        $this->seedTwoBranches();

        Livewire::actingAs(User::factory()->president()->create())
            ->test(Dashboard::class)
            ->set('statusFilter', 'Active')
            ->assertSee('SYSMEX')
            ->assertDontSee('TERUMO')
            ->set('statusFilter', 'Pulledout')
            ->assertSee('TERUMO')
            ->assertDontSee('SYSMEX');
    }

    public function test_date_range_filter_narrows_every_widget(): void
    {
        $this->seedTwoBranches();

        Livewire::actingAs(User::factory()->president()->create())
            ->test(Dashboard::class)
            ->set('dateFrom', now()->subMonths(6)->toDateString())
            ->assertSee('SYSMEX')
            ->assertDontSee('TERUMO')
            ->set('dateFrom', '')
            ->set('dateTo', now()->subMonths(6)->toDateString())
            ->assertSee('TERUMO')
            ->assertDontSee('SYSMEX');
    }

    public function test_clear_filters_resets_the_bar(): void
    {
        Livewire::actingAs(User::factory()->president()->create())
            ->test(Dashboard::class)
            ->set('branchFilter', 'Makati')
            ->set('statusFilter', 'Active')
            ->set('dateFrom', '2026-01-01')
            ->set('dateTo', '2026-02-01')
            ->assertSee('Clear filters')
            ->call('clearFilters')
            ->assertSet('branchFilter', 'All branches')
            ->assertSet('statusFilter', 'All statuses')
            ->assertSet('dateFrom', '')
            ->assertSet('dateTo', '')
            ->assertDontSee('Clear filters');
    }
}
