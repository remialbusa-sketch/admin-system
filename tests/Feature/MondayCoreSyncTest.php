<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\TechnicalReportTable;
use App\Models\Account;
use App\Models\CustomTableColumn;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\Installation;
use App\Models\MondaySyncedItem;
use App\Models\MondaySyncSetting;
use App\Models\ServiceRequest;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Services\MondayItemMapper;
use App\Services\MondaySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Core tables (the five fixed domain tables) join the monday.com sync: the
 * connect menu + board picker appear on them, board columns auto-create as
 * table columns, and items land as domain rows (source_system = monday:<key>)
 * with custom values written against the domain row id — the same storage
 * core grids already render.
 */
class MondayCoreSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBoard(array $extra = []): void
    {
        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) use ($extra) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'boards(limit')) {
                    return Http::response(['data' => ['boards' => $extra['boards'] ?? [
                        ['id' => '9', 'name' => 'Field Reports'],
                    ]]]);
                }

                if (str_contains($query, 'settings_str')) {
                    return Http::response([
                        'data' => ['boards' => [['id' => '9', 'columns' => $extra['columns'] ?? [
                            ['id' => 'text8', 'title' => 'Site Name', 'type' => 'text', 'settings_str' => ''],
                            ['id' => 'status', 'title' => 'Stage', 'type' => 'status', 'settings_str' => '{"labels":{"0":"Open","1":"Closed"}}'],
                        ]]]],
                    ]);
                }

                if (str_contains($query, 'items_page')) {
                    return Http::response([
                        'data' => ['boards' => [['id' => '9', 'items_page' => ['cursor' => null, 'items' => $extra['items'] ?? [
                            ['id' => '77', 'name' => 'Site Alpha', 'created_at' => null, 'updated_at' => null],
                        ]]]]],
                    ]);
                }

                if (str_contains($query, 'query Items')) {
                    return Http::response([
                        'data' => ['items' => $extra['fullItems'] ?? [[
                            'id' => '77',
                            'name' => 'Site Alpha',
                            'created_at' => null,
                            'updated_at' => null,
                            'column_values' => [
                                ['id' => 'text8', 'text' => 'Alpha Hospital', 'type' => 'text'],
                                ['id' => 'status', 'text' => 'Closed', 'type' => 'status', 'label' => 'Closed'],
                            ],
                        ]]],
                    ]);
                }

                return Http::response(['data' => []]);
            },
        ]);
    }

    public function test_sync_domain_imports_board_items_into_core_table(): void
    {
        $this->fakeBoard();

        MondaySyncSetting::create(['domain' => 'technical-reports', 'board_id' => '9', 'enabled' => true]);

        // Connect-style auto-map: board columns become table columns + field map.
        app(MondayItemMapper::class)->autoCreateColumns('technical-reports', [
            ['id' => 'text8', 'title' => 'Site Name', 'type' => 'text', 'settings_str' => ''],
            ['id' => 'status', 'title' => 'Stage', 'type' => 'status', 'settings_str' => '{"labels":{"0":"Open","1":"Closed"}}'],
        ]);

        $result = app(MondaySyncService::class)->syncDomain('technical-reports');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['imported']);

        $report = TechnicalReport::query()
            ->where('source_system', 'monday:technical-reports')
            ->where('source_record_id', '77')
            ->firstOrFail();

        // Item name lands in the core title field (reference_number default).
        $this->assertSame('Site Alpha', $report->reference_number);

        // Board column values land as custom values against the domain row id.
        $siteCol = CustomTableColumn::query()->where('table_key', 'technical-reports')->where('name', 'Site Name')->firstOrFail();
        $stageCol = CustomTableColumn::query()->where('table_key', 'technical-reports')->where('name', 'Stage')->firstOrFail();

        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $siteCol->id,
            'row_id' => $report->id,
            'value_text' => 'Alpha Hospital',
        ]);
        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $stageCol->id,
            'row_id' => $report->id,
            'value_text' => 'Closed',
        ]);

        $this->assertSame('imported', MondaySyncedItem::query()->where('domain', 'technical-reports')->where('item_id', '77')->value('state'));
    }

    public function test_sync_is_idempotent_for_core_rows(): void
    {
        $this->fakeBoard();

        MondaySyncSetting::create(['domain' => 'technical-reports', 'board_id' => '9', 'enabled' => true]);
        app(MondayItemMapper::class)->autoCreateColumns('technical-reports', [
            ['id' => 'text8', 'title' => 'Site Name', 'type' => 'text', 'settings_str' => ''],
        ]);

        $service = app(MondaySyncService::class);
        $service->syncDomain('technical-reports');
        $second = $service->syncDomain('technical-reports');

        $this->assertSame(0, $second['new']);
        $this->assertSame(1, TechnicalReport::query()->where('source_system', 'monday:technical-reports')->count());
    }

    public function test_title_field_override_maps_item_name_to_chosen_column(): void
    {
        $this->fakeBoard();

        MondaySyncSetting::create([
            'domain' => 'technical-reports',
            'board_id' => '9',
            'enabled' => true,
            'title_field' => 'customer_name',
        ]);
        app(MondayItemMapper::class)->autoCreateColumns('technical-reports', [
            ['id' => 'text8', 'title' => 'Site Name', 'type' => 'text', 'settings_str' => ''],
        ]);

        app(MondaySyncService::class)->syncDomain('technical-reports');

        $report = TechnicalReport::query()->where('source_system', 'monday:technical-reports')->firstOrFail();
        $this->assertSame('Site Alpha', $report->customer_name);
        $this->assertNull($report->reference_number);
    }

    public function test_sync_all_command_includes_core_domains(): void
    {
        $this->fakeBoard();

        MondaySyncSetting::create(['domain' => 'technical-reports', 'board_id' => '9', 'enabled' => true]);
        app(MondayItemMapper::class)->autoCreateColumns('technical-reports', [
            ['id' => 'text8', 'title' => 'Site Name', 'type' => 'text', 'settings_str' => ''],
        ]);

        $this->artisan('monday:sync-all')
            ->expectsOutputToContain('[technical-reports]')
            ->assertExitCode(0);

        $this->assertSame(1, TechnicalReport::query()->where('source_system', 'monday:technical-reports')->count());
    }

    public function test_status_labels_seed_options_when_column_has_none(): void
    {
        // A pre-existing core status column with NO configured options — monday
        // labels must seed them via the shared ImportOptionSeeder boundary
        // (the flagged import bug class), not fail every cell.
        CustomTableColumn::create([
            'table_key' => 'technical-reports',
            'name' => 'Stage',
            'type' => 'status',
            'position' => 0,
        ]);

        $this->fakeBoard([
            'columns' => [
                ['id' => 'status', 'title' => 'Stage', 'type' => 'status', 'settings_str' => ''],
            ],
        ]);

        MondaySyncSetting::create(['domain' => 'technical-reports', 'board_id' => '9', 'enabled' => true]);
        // Column already exists -> auto-map onto it, field map written.
        app(MondayItemMapper::class)->autoCreateColumns('technical-reports', [
            ['id' => 'status', 'title' => 'Stage', 'type' => 'status', 'settings_str' => ''],
        ]);

        $result = app(MondaySyncService::class)->syncDomain('technical-reports');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['imported']);

        $column = CustomTableColumn::query()->where('table_key', 'technical-reports')->where('name', 'Stage')->firstOrFail();
        $labels = array_column($column->settings['options'] ?? [], 'label');
        $this->assertContains('Closed', $labels);
    }

    public function test_connect_board_on_core_table_creates_columns_and_backfills(): void
    {
        $this->fakeBoard();

        $user = User::factory()->superadmin()->create();

        Livewire::actingAs($user)
            ->test(TechnicalReportTable::class)
            ->call('openConnectBoard')
            ->assertSet('showConnectBoardModal', true)
            ->assertSet('mondayBoards.0.name', 'Field Reports')
            ->set('connectBoardId', '9')
            ->call('connectBoard');

        $setting = MondaySyncSetting::forDomain('technical-reports');
        $this->assertSame('9', $setting->board_id);
        $this->assertTrue($setting->enabled); // backfill default ON

        $this->assertSame(2, CustomTableColumn::query()->where('table_key', 'technical-reports')->count());
        $this->assertSame(1, TechnicalReport::query()->where('source_system', 'monday:technical-reports')->count());
    }

    public function test_connect_board_without_backfill_does_not_import(): void
    {
        $this->fakeBoard();

        $user = User::factory()->superadmin()->create();

        Livewire::actingAs($user)
            ->test(TechnicalReportTable::class)
            ->call('openConnectBoard')
            ->set('connectBoardId', '9')
            ->set('mondayBackfill', false)
            ->call('connectBoard');

        $setting = MondaySyncSetting::forDomain('technical-reports');
        $this->assertSame('9', $setting->board_id);
        $this->assertFalse($setting->enabled);
        $this->assertSame(0, TechnicalReport::query()->where('source_system', 'monday:technical-reports')->count());
    }

    public function test_non_import_user_cannot_connect_board_on_core_table(): void
    {
        $user = User::factory()->create(['role' => UserRole::NationalManager, 'permission' => 'viewer']);

        Livewire::actingAs($user)
            ->test(TechnicalReportTable::class)
            ->call('openConnectBoard')
            ->assertForbidden();
    }

    public function test_dynamic_table_connect_still_works_with_backfill(): void
    {
        $this->fakeBoard();

        $user = User::factory()->superadmin()->create();
        $table = DynamicTable::create(['key' => 'site-log', 'name' => 'Site Log']);

        Livewire::actingAs($user)
            ->test(\App\Livewire\DynamicTable::class, ['table' => 'site-log'])
            ->call('openConnectBoard')
            ->set('connectBoardId', '9')
            ->call('connectBoard');

        $this->assertSame('9', $table->fresh()->monday_board_id);
        $this->assertSame('9', MondaySyncSetting::forDomain('site-log')->board_id);
        $this->assertSame(1, DynamicRow::query()->where('table_key', 'site-log')->where('source_record_id', '77')->count());
    }

    /**
     * Connect installed-products to a board whose Customer Name / Address /
     * Branch columns carry the account identity, then map one item.
     */
    private function mapInstallationItem(array $columnValues, string $itemId = '501'): Installation
    {
        MondaySyncSetting::create(['domain' => 'installed-products', 'board_id' => '9', 'enabled' => true]);

        app(MondayItemMapper::class)->autoCreateColumns('installed-products', [
            ['id' => 'lookup1', 'title' => 'Customer Name', 'type' => 'lookup', 'settings_str' => ''],
            ['id' => 'addr1', 'title' => 'Address', 'type' => 'text', 'settings_str' => ''],
            ['id' => 'branch1', 'title' => 'Branch', 'type' => 'status', 'settings_str' => ''],
        ]);

        app(MondayItemMapper::class)->mapItem('installed-products', [
            'id' => $itemId,
            'name' => 'SN-04589',
            'column_values' => $columnValues,
        ]);

        return Installation::query()
            ->where('source_system', 'monday:installed-products')
            ->where('source_record_id', $itemId)
            ->firstOrFail();
    }

    public function test_installation_attaches_to_account_from_board_customer_name(): void
    {
        $row = $this->mapInstallationItem([
            ['id' => 'lookup1', 'type' => 'lookup', 'text' => null, 'display_value' => 'RHU Bontoc'],
            ['id' => 'addr1', 'type' => 'text', 'text' => '123 Jail St'],
            ['id' => 'branch1', 'type' => 'status', 'text' => 'NLR1', 'label' => 'NLR1'],
        ]);

        $account = Account::query()->findOrFail($row->account_id);

        // The grid's Customer Name/Address/Branch and every dashboard
        // aggregation read account.* — the item's real customer must land
        // there, not in the 'monday.com import' placeholder.
        $this->assertSame('RHU Bontoc', $account->customer_name);
        $this->assertSame('123 Jail St', $account->customer_address);
        $this->assertSame('NLR1', $account->branch);
        $this->assertSame('monday', $account->source_system);
    }

    public function test_account_with_the_same_customer_name_is_reused_not_duplicated(): void
    {
        $existing = Account::query()->create([
            'source_system' => 'product_database',
            'source_record_id' => 'RHU Bontoc',
            'customer_name' => 'RHU Bontoc',
            'customer_address' => 'Workbook address',
        ]);

        $row = $this->mapInstallationItem([
            ['id' => 'lookup1', 'type' => 'lookup', 'text' => null, 'display_value' => 'RHU Bontoc'],
            ['id' => 'addr1', 'type' => 'text', 'text' => 'Board address'],
        ]);

        // One customer, one account across sources: the board row attaches
        // to the workbook-imported account instead of minting a twin.
        $this->assertSame($existing->id, $row->account_id);
        $this->assertSame(1, Account::query()->where('customer_name', 'RHU Bontoc')->count());

        // The workbook owns this account's identity; the board must not rewrite it.
        $this->assertSame('Workbook address', $existing->fresh()->customer_address);
    }

    public function test_item_without_a_board_customer_name_keeps_the_placeholder_account(): void
    {
        $row = $this->mapInstallationItem([
            ['id' => 'lookup1', 'type' => 'lookup', 'text' => null, 'display_value' => ''],
            ['id' => 'addr1', 'type' => 'text', 'text' => ''],
        ]);

        $placeholder = Account::query()
            ->where('source_system', 'monday')
            ->where('source_record_id', 'account-installed-products')
            ->firstOrFail();

        // account_id is NOT NULL — an item with no customer identity still
        // needs a stable parent.
        $this->assertSame($placeholder->id, $row->account_id);
        $this->assertSame('monday.com import', $placeholder->customer_name);
    }

    public function test_board_values_fill_the_domain_columns_not_only_the_customs(): void
    {
        // The grids, filters and dashboards read the DOMAIN columns — a sync
        // that only writes the custom twins leaves the core columns empty
        // (the flagged "empty Brand / Serial Number / BU No." report).
        MondaySyncSetting::create(['domain' => 'installed-products', 'board_id' => '9', 'enabled' => true]);

        app(MondayItemMapper::class)->autoCreateColumns('installed-products', [
            ['id' => 'brand1', 'title' => 'Brand', 'type' => 'text', 'settings_str' => ''],
            ['id' => 'serial1', 'title' => 'Serial Number', 'type' => 'text', 'settings_str' => ''],
            ['id' => 'bu1', 'title' => 'BU No.', 'type' => 'status', 'settings_str' => '{"labels":{"0":"BU-02"}}'],
            ['id' => 'model1', 'title' => 'Model', 'type' => 'text', 'settings_str' => ''],
            ['id' => 'ds1', 'title' => 'DEVICE STATUS', 'type' => 'status', 'settings_str' => '{"labels":{"0":"Active"}}'],
            ['id' => 'inst1', 'title' => 'INSTALLATION DATE', 'type' => 'date', 'settings_str' => ''],
        ]);

        app(MondayItemMapper::class)->mapItem('installed-products', [
            'id' => '600',
            'name' => 'SN-04589',
            'column_values' => [
                ['id' => 'brand1', 'type' => 'text', 'text' => 'SYSMEX'],
                ['id' => 'serial1', 'type' => 'text', 'text' => '23650'],
                ['id' => 'bu1', 'type' => 'status', 'text' => 'BU-02', 'label' => 'BU-02', 'index' => 0],
                ['id' => 'model1', 'type' => 'text', 'text' => 'XN-550'],
                ['id' => 'ds1', 'type' => 'status', 'text' => 'Active', 'label' => 'Active', 'index' => 0],
                ['id' => 'inst1', 'type' => 'date', 'text' => '2026-06-09', 'date' => '2026-06-09'],
            ],
        ]);

        $row = Installation::query()
            ->where('source_system', 'monday:installed-products')
            ->where('source_record_id', '600')
            ->firstOrFail();

        $this->assertSame('SYSMEX', $row->brand);
        $this->assertSame('23650', $row->serial_number);
        $this->assertSame('BU-02', $row->bu_no);
        $this->assertSame('Active', $row->device_status);
        $this->assertSame('2026-06-09', $row->installation_date?->toDateString());
        // Device description is the board's Model — the workbook holds model
        // names there ("XN-550", "UF-4000i"), never the SN item name.
        $this->assertSame('XN-550', $row->device_description);

        // The custom twin keeps working alongside the domain write.
        $brandCol = CustomTableColumn::query()->where('table_key', 'installed-products')->where('name', 'Brand')->firstOrFail();
        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $brandCol->id,
            'row_id' => $row->id,
            'value_text' => 'SYSMEX',
        ]);
    }

    public function test_item_name_never_lands_in_device_description_when_the_board_model_is_empty(): void
    {
        // The item name is a service request number ("SN-04589") — it must
        // not masquerade as a device description when Model carries nothing.
        MondaySyncSetting::create(['domain' => 'installed-products', 'board_id' => '9', 'enabled' => true]);

        app(MondayItemMapper::class)->autoCreateColumns('installed-products', [
            ['id' => 'model1', 'title' => 'Model', 'type' => 'text', 'settings_str' => ''],
        ]);

        app(MondayItemMapper::class)->mapItem('installed-products', [
            'id' => '601',
            'name' => 'SN-00001',
            'column_values' => [
                ['id' => 'model1', 'type' => 'text', 'text' => ''],
            ],
        ]);

        $row = Installation::query()
            ->where('source_system', 'monday:installed-products')
            ->where('source_record_id', '601')
            ->firstOrFail();

        $this->assertNull($row->device_description);
    }

    public function test_service_request_board_values_fill_the_domain_columns(): void
    {
        // Same class as installed-products: 4,644 synced SR rows had empty
        // ticket_status / customer_name / brand because only customs were fed.
        MondaySyncSetting::create([
            'domain' => 'service-requests',
            'board_id' => '9',
            'enabled' => true,
            'title_field' => 'service_request_number',
        ]);

        app(MondayItemMapper::class)->autoCreateColumns('service-requests', [
            ['id' => 'cn1', 'title' => 'Customer Name', 'type' => 'text', 'settings_str' => ''],
            ['id' => 'ts1', 'title' => 'TICKET STATUS', 'type' => 'status', 'settings_str' => '{"labels":{"0":"Closed"}}'],
            ['id' => 'br1', 'title' => 'Brand', 'type' => 'text', 'settings_str' => ''],
        ]);

        app(MondayItemMapper::class)->mapItem('service-requests', [
            'id' => '700',
            'name' => 'SN-00037',
            'column_values' => [
                ['id' => 'cn1', 'type' => 'text', 'text' => 'RHU Bontoc'],
                ['id' => 'ts1', 'type' => 'status', 'text' => 'Closed', 'label' => 'Closed', 'index' => 0],
                ['id' => 'br1', 'type' => 'text', 'text' => 'SYSMEX'],
            ],
        ]);

        $row = ServiceRequest::query()
            ->where('source_system', 'monday:service-requests')
            ->where('source_record_id', '700')
            ->firstOrFail();

        // The title keeps working: no board column carries the SR number.
        $this->assertSame('SN-00037', $row->service_request_number);
        $this->assertSame('RHU Bontoc', $row->customer_name);
        $this->assertSame('Closed', $row->ticket_status);
        $this->assertSame('SYSMEX', $row->brand);
    }
}
