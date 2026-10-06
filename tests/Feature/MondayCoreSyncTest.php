<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\TechnicalReportTable;
use App\Models\CustomTableColumn;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\MondaySyncedItem;
use App\Models\MondaySyncSetting;
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
}
