<?php

namespace Tests\Feature;

use App\Livewire\InstalledProductsTable;
use App\Livewire\TechnicalReportTable;
use App\Models\Account;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\Installation;
use App\Models\MondaySyncSetting;
use App\Models\TableColumnPreference;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Services\DashboardColumnService;
use App\Services\ProductDashboardService;
use App\Services\TableAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class MergedCoreColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function column(string $table, string $name, array $values): CustomTableColumn
    {
        $column = CustomTableColumn::create(['table_key' => $table, 'name' => $name, 'type' => 'text', 'position' => 1]);
        foreach ($values as $row => $text) {
            CustomTableColumnValue::create(['custom_column_id' => $column->id, 'row_id' => $row, 'value' => ['text' => $text], 'value_text' => $text]);
        }

        return $column;
    }

    private function payload($component): array
    {
        preg_match('/data-managed-table-payload>(.*?)<\/script>/s', $component->html(), $m);

        return json_decode($m[1], true);
    }

    public function test_old_and_synced_values_share_one_column_even_with_saved_source_visibility(): void
    {
        $user = User::factory()->superadmin()->create();
        $account = Account::create(['source_system' => 'test', 'source_record_id' => 'a', 'customer_name' => 'Hospital', 'branch' => 'Visayas']);
        $old = Installation::create(['account_id' => $account->id, 'source_system' => 'product_database', 'source_record_id' => 'old', 'brand' => 'Old brand']);
        $sync = Installation::create(['account_id' => $account->id, 'source_system' => 'monday:installed-products', 'source_record_id' => 'new']);
        $brand = $this->column('installed-products', 'Brand', [$sync->id => 'Synced brand', $old->id => 'Stale shadow']);
        $pms = $this->column('installed-products', 'PMS FREQUENCY', [$old->id => 'Annual']);
        $branch = $this->column('installed-products', 'Branch', [$sync->id => 'BAC']);
        MondaySyncSetting::create(['domain' => 'installed-products', 'field_map' => ['brand' => $brand->id, 'branch' => $branch->id], 'core_field_map' => ['brand' => 'brand']]);
        TableColumnPreference::create(['user_id' => $user->id, 'table_key' => 'installed-products', 'column_key' => $brand->columnKey(), 'hidden' => false]);
        $component = Livewire::actingAs($user)->test(InstalledProductsTable::class);
        $payload = $this->payload($component);
        $keys = array_column($payload['columns'], 'key');
        $this->assertNotContains($brand->columnKey(), $keys);
        $this->assertNotContains($branch->columnKey(), $keys);
        $this->assertNotContains($pms->columnKey(), $keys);
        $rows = collect($payload['rows'])->keyBy('id');
        $this->assertSame('Old brand', $rows[$old->id]['brand']);
        $this->assertSame('Synced brand', $rows[$sync->id]['brand']);
        $this->assertSame('Visayas', $rows[$sync->id]['account.branch']);
        $this->assertSame('Annual', $rows[$old->id]['pms_frequency']);
        $this->assertSame(1, app(ProductDashboardService::class)->summary()['metrics']['missing_pms']);
        $this->assertNull($sync->fresh()->brand); // Merging does not mutate source records.
        $component->set('columnFilters', ['brand' => 'Synced brand']);
        $this->assertSame([$sync->id], array_column($this->payload($component)['rows'], 'id'));
        Excel::fake();
        $component->call('exportExcel');
        Excel::assertDownloaded('product-database-'.now()->format('Y-m-d-His').'.xlsx', function ($export): bool {
            $index = array_search('Brand', $export->headings(), true);
            $this->assertSame(1, count(array_keys($export->headings(), 'Brand', true)));
            $this->assertSame('Synced brand', $export->array()[0][$index]);

            return true;
        });
    }

    public function test_model_source_values_filter_sort_and_aggregate_under_the_canonical_model_field(): void
    {
        $user = User::factory()->superadmin()->create();
        $old = TechnicalReport::create(['source_system' => 'executive_dashboard', 'source_record_id' => 'old']);
        $sync = TechnicalReport::create(['source_system' => 'monday:technical-reports', 'source_record_id' => 'new', 'machine_type' => 'XN-550']);
        $model = $this->column('technical-reports', 'Model', [$old->id => 'XN-550', $sync->id => 'Stale model']);
        $component = Livewire::actingAs($user)->test(TechnicalReportTable::class);
        $this->assertNotContains($model->columnKey(), array_column($this->payload($component)['columns'], 'key'));
        $component->set('columnFilters', ['machine_type' => 'XN-550']);
        $this->assertCount(2, $this->payload($component)['rows']);
        $chart = app(DashboardColumnService::class)->chart(['column' => 'tr.machine_type', 'aggregation' => 'count'], ['tr' => 'technical-reports'], []);
        $this->assertSame([['label' => 'XN-550', 'values' => [2.0]]], $chart['points']);
        $this->assertSame('Stale model', CustomTableColumnValue::where('row_id', $sync->id)->first()->value['text']);
        $options = app(DashboardColumnService::class)->options(['tr' => 'technical-reports']);
        $this->assertArrayNotHasKey('tr.'.$model->columnKey(), $options['tr']);
        $legacy = app(DashboardColumnService::class)->chart(['column' => 'tr.'.$model->columnKey(), 'aggregation' => 'count'], ['tr' => 'technical-reports'], []);
        $this->assertSame($chart['points'], $legacy['points']);
        $summary = app(TableAggregationService::class)->summary('technical-reports');
        $this->assertSame('XN-550', $summary['datasets']['by_machine_type'][0]['label']);
        $this->assertSame(2, $summary['datasets']['by_machine_type'][0]['total']);
        $component->set('columnFilters', [])->call('updateField', $old->id, 'machine_type', null);
        $rows = collect($this->payload($component)['rows'])->keyBy('id');
        $this->assertNull($rows[$old->id]['machine_type']);
        $chart = app(DashboardColumnService::class)->chart(['column' => 'tr.machine_type', 'aggregation' => 'count'], ['tr' => 'technical-reports'], []);
        $this->assertSame(['(Blank)', 'XN-550'], array_column($chart['points'], 'label'));
    }
}
