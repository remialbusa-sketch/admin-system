<?php

namespace Tests\Feature;

use App\Livewire\InstalledProductsTable;
use App\Models\Account;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\Installation;
use App\Models\MondaySyncSetting;
use App\Models\User;
use App\Services\ImportMappingService;
use App\Support\AttachmentValue;
use App\Support\ImportTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function cell(Installation $row, string $name, string $type, array $value): CustomTableColumn
    {
        $column = CustomTableColumn::firstOrCreate(['table_key' => 'installed-products', 'name' => $name], ['type' => $type, 'position' => 1]);
        CustomTableColumnValue::create(['custom_column_id' => $column->id, 'row_id' => $row->id, 'value' => $value]);

        return $column;
    }

    private function product(string $source, string $reference): Installation
    {
        $account = Account::firstOrCreate(['source_system' => 'product_database', 'source_record_id' => 'hospital'], ['customer_name' => 'Test Hospital']);

        return Installation::create(['account_id' => $account->id, 'source_system' => $source, 'source_record_id' => $reference, 'device_description' => $reference]);
    }

    public function test_comma_separated_qr_urls_become_individual_image_assets(): void
    {
        $urls = 'https://test.monday.com/protected_static/1/resources/123/qr.png, https://test.monday.com/protected_static/1/resources/456/qr.png';
        $files = AttachmentValue::files(['text' => $urls]);
        $this->assertSame([123, 456], array_column($files, 'asset_id'));
        $this->assertSame([true, true], array_column($files, 'is_image'));
        $grid = AttachmentValue::grid(['text' => $urls], 10, 20, false);
        $this->assertSame(route('table-files.show', [10, 20, 1]), $grid['files'][1]['url']);
        $this->assertSame([], AttachmentValue::files(['text' => $urls.', javascript:alert(1)']));
    }

    public function test_product_import_aliases_use_native_fields_instead_of_custom_twins(): void
    {
        CustomTableColumn::create(['table_key' => 'installed-products', 'name' => 'UNINSTALLATION'."\n".'DATE', 'type' => 'text', 'position' => 1]);
        $target = ImportTargetResolver::for('installed-products');
        $mapping = app(ImportMappingService::class)->suggestMapping($target['fields'], [
            ['letter' => 'A', 'label' => 'UNINSTALLATION DATE'],
            ['letter' => 'B', 'label' => 'Model'],
            ['letter' => 'C', 'label' => 'SYSTEM TYPE'],
        ]);
        $this->assertSame('A', $mapping['pulled_out_date'] ?? null);
        $this->assertArrayNotHasKey('installation_date', $mapping);
        $this->assertSame('B', $mapping['device_description'] ?? null);
        $this->assertSame('C', $mapping['system_type'] ?? null);
        $this->assertFalse(collect($target['fields'])->contains(fn ($f) => str_starts_with($f['key'], 'custom_')));
    }

    public function test_cached_product_values_repair_main_fields_without_network_or_overwriting_manual_edits(): void
    {
        Http::preventStrayRequests();
        $row = $this->product('monday:installed-products', 'SN-04739');
        $brand = $this->cell($row, 'Brand', 'text', ['text' => 'BECKMAN COULTER']);
        $model = $this->cell($row, 'Model', 'text', ['text' => 'Aquios CL']);
        $this->cell($row, 'Connection', 'text', ['text' => 'SN-04739']);
        $import = $this->product('product_database', 'Existing model');
        $this->cell($import, 'UNINSTALLATION'."\n".'DATE', 'text', ['text' => '45853']);
        $manual = $this->product('monday:installed-products', 'Manual model');
        $manual->update(['brand' => 'Manual brand']);
        $this->cell($manual, 'Brand', 'text', ['text' => 'Older cached brand']);
        $setting = MondaySyncSetting::create(['domain' => 'installed-products', 'enabled' => false, 'mapping_revision' => 0, 'field_map' => ['b' => $brand->id, 'm' => $model->id], 'core_field_map' => ['b' => 'brand', 'm' => 'device_description']]);

        $this->artisan('monday:repair-products')->assertSuccessful();
        $this->assertNull($row->fresh()->brand);
        $this->artisan('monday:repair-products --apply')->assertSuccessful();
        $this->assertSame('BECKMAN COULTER', $row->fresh()->brand);
        $this->assertSame('Aquios CL', $row->fresh()->device_description);
        $this->assertSame('2025-07-15', $import->fresh()->uninstallation_date->toDateString());
        $this->assertSame('Manual brand', $manual->fresh()->brand);
        $this->assertSame('Manual model', $manual->fresh()->device_description);
        $this->assertFalse($setting->fresh()->enabled);
        $this->assertSame(1, $setting->fresh()->mapping_revision);
        $this->artisan('monday:repair-products --apply')->expectsOutputToContain('0 products')->assertSuccessful();
        $this->assertSame(1, $setting->fresh()->mapping_revision);
        $this->assertSame(3, Installation::count());
    }

    public function test_source_twins_merge_into_the_canonical_column(): void
    {
        $row = $this->product('product_database', 'Analyzer');
        $row->update(['brand' => 'Sysmex']);
        $brand = $this->cell($row, 'Brand', 'text', ['text' => 'Sysmex']);
        MondaySyncSetting::create(['domain' => 'installed-products', 'field_map' => ['b' => $brand->id], 'core_field_map' => ['b' => 'brand']]);
        $component = Livewire::actingAs(User::factory()->superadmin()->create())->test(InstalledProductsTable::class);
        $columns = collect($component->instance()->orderedColumns())->keyBy('key');
        $this->assertArrayNotHasKey($brand->columnKey(), $columns);
        $this->assertFalse($columns['brand']['hidden']);
        $component->call('toggleColumnFreeze', 'brand');
        $component->call('moveColumnToEdge', 'brand', 'start');
        $columns = collect($component->instance()->orderedColumns())->keyBy('key');
        $this->assertArrayNotHasKey($brand->columnKey(), $columns);
        $this->assertTrue($columns['brand']['frozen']);
    }

    public function test_merging_account_columns_preserves_the_shared_account_value(): void
    {
        $row = $this->product('monday:installed-products', 'SN-123');
        $row->account->update(['branch' => 'Visayas']);
        $branch = $this->cell($row, 'Branch', 'status', ['index' => 0, 'label' => 'BAC']);
        MondaySyncSetting::create(['domain' => 'installed-products', 'field_map' => ['b' => $branch->id], 'core_field_map' => []]);
        $component = Livewire::actingAs(User::factory()->superadmin()->create())->test(InstalledProductsTable::class);
        $columns = collect($component->instance()->orderedColumns())->keyBy('key');
        $this->assertArrayNotHasKey($branch->columnKey(), $columns);
        $this->assertSame('Branch', $columns['account.branch']['label']);
        $this->assertSame('Visayas', $row->account->fresh()->branch);
    }

    public function test_invalid_dates_and_values_from_other_tables_are_preserved_without_copying(): void
    {
        $row = $this->product('product_database', 'Analyzer');
        $this->cell($row, 'UNINSTALLATION DATE', 'text', ['text' => 'not a date']);
        $foreign = CustomTableColumn::create(['table_key' => 'service-requests', 'name' => 'Brand', 'type' => 'text', 'position' => 1]);
        CustomTableColumnValue::create(['custom_column_id' => $foreign->id, 'row_id' => $row->id, 'value' => ['text' => 'Wrong table']]);
        $this->artisan('monday:repair-products --apply')->expectsOutputToContain('0 products')->expectsOutputToContain('1 invalid source dates skipped')->assertSuccessful();
        $this->assertNull($row->fresh()->brand);
        $this->assertNull($row->fresh()->uninstallation_date);
        $this->assertSame('Wrong table', CustomTableColumnValue::where('custom_column_id', $foreign->id)->first()->value['text']);
    }
}
