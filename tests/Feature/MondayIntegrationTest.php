<?php

namespace Tests\Feature;

use App\Models\CustomTableColumn;
use App\Models\DynamicTable;
use App\Models\MondaySyncedItem;
use App\Models\MondaySyncSetting;
use App\Services\MondayApiClient;
use App\Services\MondayApiException;
use App\Services\MondaySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MondayIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspect_board_queries_columns_and_item_page(): void
    {
        config(['monday.token' => 'test-token']);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $body = $request->data();
                $query = $body['query'] ?? '';

                if (str_contains($query, 'settings_str')) {
                    return Http::response([
                        'data' => ['boards' => [[
                            'id' => '123',
                            'columns' => [
                                ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => ''],
                                ['id' => 'text9', 'title' => 'Brand', 'type' => 'text', 'settings_str' => ''],
                            ],
                        ]]],
                    ]);
                }

                if (str_contains($query, 'items_page')) {
                    return Http::response([
                        'data' => ['boards' => [[
                            'id' => '123',
                            'items_page' => [
                                'cursor' => null,
                                'items' => [
                                    ['id' => '1001', 'name' => 'MRI', 'created_at' => null, 'updated_at' => null],
                                ],
                            ],
                        ]]],
                    ]);
                }

                return Http::response(['data' => ['boards' => [['columns' => []]]]], 200);
            },
        ]);

        $client = app(MondayApiClient::class);
        $columns = $client->boardColumns(123);

        $this->assertSame('status', $columns[0]['id']);
        $this->assertSame('Brand', $columns[1]['title']);

        $page = $client->itemsPage(123);
        $this->assertSame('1001', $page['items'][0]['id']);
        $this->assertNull($page['cursor']);
    }

    public function test_client_uses_raw_token_and_pinned_api_version(): void
    {
        config(['monday.token' => 'secret']);

        $captured = null;
        Http::fake([
            'https://api.monday.com/v2' => function ($request) use (&$captured) {
                $captured = $request;

                return Http::response(['data' => ['boards' => [['columns' => []]]]]);
            },
        ]);

        $client = app(MondayApiClient::class);
        $client->boardColumns(123);

        // Raw token (no Bearer prefix) + pinned API version.
        $this->assertSame('secret', $captured->header('Authorization')[0]);
        $this->assertSame('2023-10', $captured->header('API-Version')[0]);
    }

    public function test_client_throws_on_401(): void
    {
        config(['monday.token' => 'bad']);

        Http::fake([
            'https://api.monday.com/v2' => Http::response([], 401),
        ]);

        $this->expectException(MondayApiException::class);

        app(MondayApiClient::class)->boardColumns(123);
    }

    public function test_sync_service_records_only_new_item_ids(): void
    {
        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);

        MondaySyncSetting::create(['domain' => 'equipment-requests', 'board_id' => '123', 'enabled' => true]);
        DynamicTable::create([
            'key' => 'equipment-requests',
            'name' => 'Equipment Requests',
            'monday_board_id' => '123',
            // A connected table must have columns auto-mapped (field map).
            'monday_field_map' => ['status' => 1],
        ]);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $body = $request->data();
                $query = $body['query'] ?? '';

                if (str_contains($query, 'items_page')) {
                    return Http::response([
                        'data' => ['boards' => [[
                            'id' => '123',
                            'items_page' => [
                                'cursor' => null,
                                'items' => [
                                    ['id' => '1', 'name' => 'A', 'created_at' => null, 'updated_at' => null],
                                    ['id' => '2', 'name' => 'B', 'created_at' => null, 'updated_at' => null],
                                ],
                            ],
                        ]]],
                    ]);
                }

                if (str_contains($query, 'query Items')) {
                    $ids = $body['variables']['ids'] ?? [];

                    return Http::response([
                        'data' => ['items' => collect($ids)->map(fn ($id) => [
                            'id' => (string) $id,
                            'name' => 'Item '.$id,
                            'created_at' => null,
                            'updated_at' => null,
                            'column_values' => [],
                        ])->all()],
                    ]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        $service = app(MondaySyncService::class);
        $result = $service->syncDomain('equipment-requests');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['new']);
        $this->assertCount(2, MondaySyncedItem::query()->where('domain', 'equipment-requests')->get());

        // Second run: no new items (both already seen).
        $result2 = $service->syncDomain('equipment-requests');
        $this->assertSame(0, $result2['new']);
        $this->assertCount(2, MondaySyncedItem::query()->where('domain', 'equipment-requests')->get());
    }

    public function test_inspect_board_command_outputs_columns(): void
    {
        config(['monday.token' => 'test-token']);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'settings_str')) {
                    return Http::response([
                        'data' => ['boards' => [[
                            'id' => '5',
                            'columns' => [
                                ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => ''],
                            ],
                        ]]],
                    ]);
                }

                return Http::response([
                    'data' => ['boards' => [[
                        'items_page' => ['cursor' => null, 'items' => [['id' => '9']]],
                    ]]],
                ]);
            },
        ]);

        $this->artisan('monday:inspect-board', ['board_id' => '5'])
            ->expectsOutputToContain('Status')
            ->assertExitCode(0);
    }

    public function test_inspect_board_fails_without_token(): void
    {
        config(['monday.token' => null]);

        $this->artisan('monday:inspect-board', ['board_id' => '5'])
            ->expectsOutputToContain('not configured')
            ->assertExitCode(1);
    }

    public function test_register_webhook_command_registers_and_reports_id(): void
    {
        config(['monday.token' => 't']);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'create_webhook')) {
                    return Http::response([
                        'data' => ['create_webhook' => ['id' => '9876', 'board_id' => '123']],
                    ]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        $this->artisan('monday:register-webhook', ['board_id' => '123'])
            ->expectsOutputToContain('Webhook #9876 registered')
            ->assertExitCode(0);
    }

    public function test_client_can_list_board_webhooks(): void
    {
        config(['monday.token' => 't']);

        Http::fake([
            'https://api.monday.com/v2' => fn () => Http::response([
                'data' => ['boards' => [[
                    'id' => '123',
                    'webhooks' => [['id' => '9876', 'event' => 'create_item', 'board_id' => '123']],
                ]]],
            ]),
        ]);

        $webhooks = app(MondayApiClient::class)->boardWebhooks(123);

        $this->assertCount(1, $webhooks);
        $this->assertSame('9876', $webhooks[0]['id']);
        $this->assertSame('create_item', $webhooks[0]['event']);
    }

    public function test_sync_all_imports_across_multiple_enabled_domains(): void
    {
        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);

        // Give each connected table a real custom column that the board maps to,
        // so mapItem succeeds and the imported state can be asserted.
        foreach (['a', 'b'] as $key) {
            CustomTableColumn::create([
                'table_key' => $key,
                'name' => 'Status',
                'type' => 'text',
                'position' => 0,
            ]);
        }
        $colA = CustomTableColumn::query()->where('table_key', 'a')->where('name', 'Status')->value('id');
        $colB = CustomTableColumn::query()->where('table_key', 'b')->where('name', 'Status')->value('id');

        // Two connected+toggle-on tables, one toggle-off (should be skipped).
        MondaySyncSetting::create(['domain' => 'a', 'board_id' => '1', 'enabled' => true]);
        DynamicTable::create(['key' => 'a', 'name' => 'A', 'monday_board_id' => '1', 'monday_field_map' => ['status' => $colA]]);
        MondaySyncSetting::create(['domain' => 'b', 'board_id' => '2', 'enabled' => true]);
        DynamicTable::create(['key' => 'b', 'name' => 'B', 'monday_board_id' => '2', 'monday_field_map' => ['status' => $colB]]);
        MondaySyncSetting::create(['domain' => 'off', 'board_id' => '9', 'enabled' => false]);
        DynamicTable::create(['key' => 'off', 'name' => 'Off', 'monday_board_id' => '9']);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $body = $request->data();
                $query = $body['query'] ?? '';
                $vars = $body['variables'] ?? [];
                $candidate = (string) (($vars['ids'][0] ?? null) ?: '10-1');

                if (str_contains($query, 'items_page')) {
                    $board = (string) ($vars['boardId'] ?? '0');

                    return Http::response([
                        'data' => ['boards' => [[
                            'id' => $board,
                            'items_page' => ['cursor' => null, 'items' => [
                                ['id' => $board.'-1', 'name' => 'Item', 'created_at' => null, 'updated_at' => null],
                            ]],
                        ]]],
                    ]);
                }

                if (str_contains($query, 'query Items')) {
                    return Http::response([
                        'data' => ['items' => [[
                            'id' => $candidate,
                            'name' => 'Real item',
                            'created_at' => null,
                            'updated_at' => null,
                            'column_values' => [['id' => 'status', 'text' => 'Done', 'type' => 'text']],
                        ]]],
                    ]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        $this->artisan('monday:sync-all')
            ->expectsOutputToContain('[a]')
            ->expectsOutputToContain('[b]')
            ->assertExitCode(0);

        // Both turned-on tables got their item imported; the off one did not.
        $this->assertSame('imported', MondaySyncedItem::query()->where('domain', 'a')->value('state'));
        $this->assertSame('imported', MondaySyncedItem::query()->where('domain', 'b')->value('state'));
        $this->assertDatabaseCount('monday_synced_items', 2);
    }

    public function test_failed_mapping_is_retried_next_run(): void
    {
        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);

        // A real number column; the incoming monday value is non-numeric, so
        // NumberColumnType::validate() throws -> mapping fails -> 'failed'.
        CustomTableColumn::create([
            'table_key' => 'retry',
            'name' => 'Units',
            'type' => 'number',
            'position' => 0,
        ]);
        $colId = CustomTableColumn::query()->where('table_key', 'retry')->where('name', 'Units')->value('id');

        MondaySyncSetting::create(['domain' => 'retry', 'board_id' => '9', 'enabled' => true]);
        DynamicTable::create([
            'key' => 'retry',
            'name' => 'Retry',
            'monday_board_id' => '9',
            'monday_field_map' => ['units' => $colId],
        ]);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'items_page')) {
                    return Http::response([
                        'data' => ['boards' => [[
                            'id' => '9',
                            'items_page' => ['cursor' => null, 'items' => [
                                ['id' => '77', 'name' => 'X', 'created_at' => null, 'updated_at' => null],
                            ]],
                        ]]],
                    ]);
                }

                if (str_contains($query, 'query Items')) {
                    return Http::response([
                        'data' => ['items' => [[
                            'id' => '77',
                            'name' => 'X',
                            'created_at' => null,
                            'updated_at' => null,
                            'column_values' => [['id' => 'units', 'text' => 'not-a-number', 'type' => 'numbers']],
                        ]]],
                    ]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        $service = app(MondaySyncService::class);

        // First run: mapping fails (invalid number) -> recorded 'failed'.
        $result = $service->syncDomain('retry');
        $this->assertSame(1, $result['failed']);
        $this->assertSame('failed', MondaySyncedItem::query()->where('domain', 'retry')->where('item_id', '77')->value('state'));

        // Because it's failed (not imported), a second run still treats it as a
        // candidate (retries it) rather than skipping it.
        $result2 = $service->syncDomain('retry');
        $this->assertSame(1, $result2['new']);
        $this->assertSame(1, $result2['failed']);
    }

    public function test_sync_service_skips_when_toggle_off(): void
    {
        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);

        MondaySyncSetting::create(['domain' => 'x', 'board_id' => '123', 'enabled' => false]);

        Http::fake(['https://api.monday.com/v2' => Http::response(['data' => []])]);

        $result = app(MondaySyncService::class)->syncDomain('x');

        $this->assertSame('disabled', $result['status']);
        $this->assertSame(0, $result['seen']);
    }

    public function test_sync_service_skips_when_globally_disabled(): void
    {
        config(['monday.enabled' => false]);
        MondaySyncSetting::create(['domain' => 'x', 'board_id' => '123', 'enabled' => true]);

        Http::fake(['https://api.monday.com/v2' => Http::response(['data' => []])]);

        $result = app(MondaySyncService::class)->syncDomain('x');

        $this->assertSame('disabled', $result['status']);
    }
}
