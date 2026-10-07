<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Settings;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MondayApiClient;
use App\Support\MondaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * monday.com credentials live in the database (superadmin Settings page),
 * not in .env: MondaySettings reads system_settings first and falls back to
 * config. Boundary tests: token precedence, the global kill switch, the
 * superadmin-only save, and the test-connection action.
 */
class MondaySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_token_overrides_env_for_api_client(): void
    {
        SystemSetting::set('monday.api_token', 'db-secret');
        config(['monday.token' => 'env-secret']);

        $captured = null;
        Http::fake([
            'https://api.monday.com/v2' => function ($request) use (&$captured) {
                $captured = $request;

                return Http::response(['data' => ['boards' => [['columns' => []]]]]);
            },
        ]);

        app(MondayApiClient::class)->boardColumns(1);

        $this->assertSame('db-secret', $captured->header('Authorization')[0]);
    }

    public function test_env_token_used_when_no_db_row(): void
    {
        config(['monday.token' => 'env-only']);

        $captured = null;
        Http::fake([
            'https://api.monday.com/v2' => function ($request) use (&$captured) {
                $captured = $request;

                return Http::response(['data' => ['boards' => [['columns' => []]]]]);
            },
        ]);

        app(MondayApiClient::class)->boardColumns(1);

        $this->assertSame('env-only', $captured->header('Authorization')[0]);
    }

    public function test_enabled_flag_falls_back_to_env_and_db_overrides(): void
    {
        config(['monday.enabled' => true]);
        $this->assertTrue(MondaySettings::enabled());

        SystemSetting::set('monday.enabled', '0');
        $this->assertFalse(MondaySettings::enabled());

        SystemSetting::set('monday.enabled', '1');
        $this->assertTrue(MondaySettings::enabled());
    }

    public function test_superadmin_saves_token_and_enable_flag(): void
    {
        $user = User::factory()->superadmin()->create();

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('mondayToken', 'new-token-123')
            ->set('mondayEnabled', true)
            ->call('saveMondaySettings');

        $this->assertSame('new-token-123', SystemSetting::get('monday.api_token'));
        $this->assertSame('1', SystemSetting::get('monday.enabled'));
    }

    public function test_saving_without_new_token_keeps_the_existing_one(): void
    {
        SystemSetting::set('monday.api_token', 'keep-me');

        $user = User::factory()->superadmin()->create();

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('mondayToken', '')
            ->set('mondayEnabled', true)
            ->call('saveMondaySettings');

        $this->assertSame('keep-me', SystemSetting::get('monday.api_token'));
        $this->assertSame('1', SystemSetting::get('monday.enabled'));
    }

    public function test_non_superadmin_cannot_save_monday_settings(): void
    {
        $user = User::factory()->create(['role' => UserRole::NationalManager]);

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('mondayToken', 'sneaky')
            ->call('saveMondaySettings')
            ->assertForbidden();

        $this->assertNull(SystemSetting::get('monday.api_token'));
    }

    public function test_non_superadmin_cannot_test_connection(): void
    {
        $user = User::factory()->create(['role' => UserRole::President]);

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->call('testMondaySettings')
            ->assertForbidden();
    }

    public function test_test_connection_reports_visible_board_count(): void
    {
        SystemSetting::set('monday.api_token', 't');

        Http::fake([
            'https://api.monday.com/v2' => Http::response([
                'data' => ['boards' => [
                    ['id' => '1', 'name' => 'Board One'],
                    ['id' => '2', 'name' => 'Board Two'],
                    ['id' => '3', 'name' => 'Board Three'],
                ]],
            ]),
        ]);

        $user = User::factory()->superadmin()->create();

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->call('testMondaySettings')
            ->assertSee('3');
    }

    public function test_test_connection_surfaces_token_rejection(): void
    {
        SystemSetting::set('monday.api_token', 'bad');

        Http::fake([
            'https://api.monday.com/v2' => Http::response([], 401),
        ]);

        $user = User::factory()->superadmin()->create();

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->call('testMondaySettings')
            ->assertSee('401');
    }

    public function test_client_lists_boards_with_names(): void
    {
        config(['monday.token' => 't']);

        Http::fake([
            'https://api.monday.com/v2' => Http::response([
                'data' => ['boards' => [
                    ['id' => '5028296070', 'name' => 'Product Database'],
                    ['id' => '5028514175', 'name' => 'Tickets'],
                ]],
            ]),
        ]);

        $boards = app(MondayApiClient::class)->boards();

        $this->assertCount(2, $boards);
        $this->assertSame('Product Database', $boards[0]['name']);
        $this->assertSame('5028296070', $boards[0]['id']);
    }

    public function test_boards_query_sends_variables_as_a_json_object(): void
    {
        // Live-API incident 2026-10-07: monday.com answers an empty PHP
        // array (encoded as "variables":[]) with HTTP 400
        // INVALID_GRAPHQL_REQUEST — it requires a JSON object ("variables":{}).
        // Http::fake never validates the payload, so the bug reached prod
        // (Test connection + board picker both failed). Pin the wire format.
        config(['monday.token' => 't']);

        $captured = null;
        Http::fake([
            'https://api.monday.com/v2' => function ($request) use (&$captured) {
                $captured = $request;

                return Http::response(['data' => ['boards' => []]]);
            },
        ]);

        app(MondayApiClient::class)->boards();

        $this->assertStringContainsString('"variables":{}', $captured->body());
        $this->assertStringNotContainsString('"variables":[]', $captured->body());
    }

    public function test_items_query_sends_ids_as_a_json_array_even_when_keyed(): void
    {
        // Sync incident 2026-10-07: Collection::chunk() keeps the source
        // keys, so backfill batch 2 (keys "100".."199") encoded its ids as
        // a JSON OBJECT and monday answered "ID cannot represent a
        // non-string and non-integer value" — aborting the whole run. List-
        // typed variables must always reach monday as JSON arrays.
        config(['monday.token' => 't']);

        $captured = null;
        Http::fake([
            'https://api.monday.com/v2' => function ($request) use (&$captured) {
                $captured = $request;

                return Http::response(['data' => ['items' => []]]);
            },
        ]);

        app(MondayApiClient::class)->items([100 => '2834964040', 101 => '2835497112']);

        $body = json_decode($captured->body(), true);

        $this->assertTrue(
            array_is_list($body['variables']['ids'] ?? null),
            'variables.ids must encode as a JSON array, even from a keyed PHP array.',
        );
        $this->assertSame(['2834964040', '2835497112'], $body['variables']['ids']);
    }

    public function test_items_query_fetches_board_relation_display_value(): void
    {
        // Customer Name incident 2026-10-07: board_relation columns return
        // EMPTY at the ColumnValue interface level (text/value = "") — the
        // linked names only exist on the BoardRelationValue type fragment
        // (display_value = "AppleOne Brokenshire Medical Center", 197/200
        // items live). Without the fragment the mapper sees no value and
        // clears the cell, which is why "Customer Name" stayed empty while
        // being fully populated on the board. Http::fake never validates the
        // query shape, so pin it on the raw body.
        config(['monday.token' => 't']);

        $captured = null;
        Http::fake([
            'https://api.monday.com/v2' => function ($request) use (&$captured) {
                $captured = $request;

                return Http::response(['data' => ['items' => []]]);
            },
        ]);

        app(MondayApiClient::class)->items(['2834964040']);

        $this->assertStringContainsString('BoardRelationValue', $captured->body());
        $this->assertStringContainsString('display_value', $captured->body());
    }
}
