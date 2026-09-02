<?php

namespace Tests\Feature;

use App\Jobs\MondaySyncItemJob;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\MondaySyncSetting;
use App\Services\MondayItemMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MondayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function connectTable(string $key = 'equipment'): DynamicTable
    {
        $table = DynamicTable::create([
            'key' => $key,
            'name' => ucfirst($key),
            'monday_board_id' => '123',
        ]);

        MondaySyncSetting::updateOrCreate(
            ['domain' => $key],
            ['board_id' => '123', 'enabled' => true],
        );

        return $table;
    }

    public function test_challenge_handshake_echoes(): void
    {
        config(['monday.enabled' => true]);

        $this->postJson('/webhooks/monday', ['challenge' => 'abc123'])
            ->assertOk()
            ->assertJson(['challenge' => 'abc123']);
    }

    public function test_create_item_webhook_dispaches_job_and_dedups(): void
    {
        config(['monday.enabled' => true]);
        $this->connectTable();

        Queue::fake();

        $payload = [
            'event' => [
                'type' => 'create_pulse',
                'triggerUuid' => 'uuid-111',
                'pulseId' => '50',
                'boardId' => '123',
            ],
        ];

        $this->postJson('/webhooks/monday', $payload)->assertOk()->assertJson(['ok' => true]);

        Queue::assertPushed(MondaySyncItemJob::class, fn ($job) => $job->itemId === '50' && (string) $job->boardId === '123');

        // Duplicate delivery is dropped.
        $this->postJson('/webhooks/monday', $payload)->assertOk();
        Queue::assertPushed(MondaySyncItemJob::class, 1);

        $this->assertDatabaseHas('monday_webhook_events', ['trigger_uuid' => 'uuid-111']);
    }

    public function test_non_create_event_is_acknowledged_but_no_job(): void
    {
        config(['monday.enabled' => true]);
        $this->connectTable();

        Queue::fake();

        $this->postJson('/webhooks/monday', [
            'event' => ['type' => 'change_column_value', 'triggerUuid' => 'uuid-222', 'pulseId' => '60', 'boardId' => '123'],
        ])->assertOk()->assertJson(['ok' => true]);

        Queue::assertNotPushed(MondaySyncItemJob::class);
    }

    public function test_webhook_job_maps_item_into_table_end_to_end(): void
    {
        config(['monday.enabled' => true]);
        config(['monday.token' => 't']);
        $table = $this->connectTable();

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $body = $request->data();
                $query = $body['query'] ?? '';

                if (str_contains($query, 'settings_str')) {
                    return Http::response([
                        'data' => ['boards' => [['id' => '123', 'columns' => [
                            ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => '{"labels":{"0":"New","1":"In Progress","2":"Done"}}'],
                            ['id' => 'text9', 'title' => 'Brand', 'type' => 'text', 'settings_str' => ''],
                        ]]]],
                    ]);
                }

                return Http::response([
                    'data' => ['items' => [[
                        'id' => '50',
                        'name' => 'Device X',
                        'created_at' => null,
                        'updated_at' => null,
                        'column_values' => [
                            ['id' => 'status', 'text' => 'Done', 'type' => 'status', 'label' => 'Done'],
                            ['id' => 'text9', 'text' => 'GE', 'type' => 'text'],
                        ],
                    ]]],
                ]);
            },
        ]);

        // Auto-create columns (as connect would have), then dispatch the webhook job.
        app(MondayItemMapper::class)->autoCreateColumns($table, collect([
            ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => '{"labels":{"0":"New","1":"In Progress","2":"Done"}}'],
            ['id' => 'text9', 'title' => 'Brand', 'type' => 'text'],
        ])->all());

        MondaySyncItemJob::dispatchSync('50', '123', 'uuid-333');

        $row = DynamicRow::query()->where('table_key', $table->key)->where('source_record_id', '50')->firstOrFail();
        $this->assertSame('Device X', $row->name);

        $this->assertDatabaseHas('table_custom_column_values', [
            'row_id' => $row->id,
            'value_text' => 'GE',
        ]);
    }
}
