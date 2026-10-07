<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\DynamicTable;
use App\Models\DynamicRow;
use App\Models\DynamicTable as DynamicTableModel;
use App\Models\MondaySyncedItem;
use App\Models\MondaySyncSetting;
use App\Models\User;
use App\Services\MondayApiException;
use App\Services\MondaySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sync batching contract (prod incident 2026-10-07): Collection::chunk()
 * keeps source keys, so batch 2 of the backfill encoded its ids as a JSON
 * OBJECT and monday rejected the whole run before anything was mapped.
 * Requirements: JSON-array batches of at most 25 ids (monday's items(ids:)
 * hard cap — verified live 2026-10-07: requests of 26/30/40 answer with 25,
 * silently truncating larger ones), one batch failing must not discard the
 * others, candidates fetched oldest-first, and the grid shows the newest rows
 * first (server order, default id-desc).
 */
class MondaySyncBatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeDomain(): void
    {
        DynamicTableModel::create([
            'key' => 'equipment',
            'name' => 'Equipment',
            'monday_board_id' => '123',
            'created_by' => null,
        ]);

        MondaySyncSetting::updateOrCreate(['domain' => 'equipment'], [
            'board_id' => '123',
            'enabled' => true,
            'field_map' => ['text9' => 999],
        ]);

        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);
    }

    /**
     * Board listing: `$count` items with strictly increasing created_at,
     * returned NEWEST first (like monday's own items_page).
     *
     * @return array<int, array{id: string, name: string, created_at: string, updated_at: null}>
     */
    private function boardItems(int $count): array
    {
        $items = [];

        for ($i = 1; $i <= $count; $i++) {
            $items[] = [
                'id' => (string) (1000 + $i),
                'name' => 'Item '.$i,
                'created_at' => sprintf('2026-01-01T%02d:%02d:00+00:00', intdiv($i, 60), $i % 60),
                'updated_at' => null,
            ];
        }

        return array_reverse($items);
    }

    public function test_sync_fetches_candidates_oldest_first_in_json_array_batches(): void
    {
        $this->makeDomain();
        $items = $this->boardItems(120);

        $idBatches = [];
        Http::fake([
            'https://api.monday.com/v2' => function ($request) use (&$idBatches, $items) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'items_page')) {
                    return Http::response(['data' => ['boards' => [['id' => '123', 'items_page' => ['cursor' => null, 'items' => $items]]]]]);
                }

                if (str_contains($query, 'query Items')) {
                    $body = json_decode($request->body(), true);
                    $ids = $body['variables']['ids'] ?? null;
                    $idBatches[] = $ids;

                    $fetched = collect(is_array($ids) ? $ids : [])->map(fn ($id): array => [
                        'id' => (string) $id,
                        'name' => 'Item '.$id,
                        'created_at' => null,
                        'updated_at' => null,
                        'column_values' => [],
                    ])->values()->all();

                    return Http::response(['data' => ['items' => $fetched]]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        $result = app(MondaySyncService::class)->syncDomain('equipment');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(120, $result['new']);
        $this->assertSame(120, $result['imported']);

        // monday's items(ids:) hard-caps at 25 ids per query (verified live
        // 2026-10-07: requests of 26/30/40 all answer with 25) — 120
        // candidates = 5 batches (25/25/25/25/20), none larger than 25.
        $this->assertCount(5, $idBatches);

        foreach ($idBatches as $ids) {
            $this->assertTrue(array_is_list($ids), 'Batch ids must encode as a JSON array.');
            $this->assertLessThanOrEqual(25, count($ids), 'A batch must never exceed monday\'s 25-id items() cap.');
        }

        // Oldest candidates first, regardless of the board's listing order.
        $oldestFirst = collect($items)->sortBy(fn (array $item): string => $item['created_at'])->pluck('id')->all();
        $this->assertSame(array_slice($oldestFirst, 0, 25), $idBatches[0]);
        $this->assertSame(array_slice($oldestFirst, 25, 25), $idBatches[1]);
        $this->assertSame(array_slice($oldestFirst, 50, 25), $idBatches[2]);
        $this->assertSame(array_slice($oldestFirst, 75, 25), $idBatches[3]);
        $this->assertSame(array_slice($oldestFirst, 100, 20), $idBatches[4]);

        $this->assertSame(120, DynamicRow::query()->where('table_key', 'equipment')->count());
    }

    public function test_a_failed_batch_does_not_discard_the_batches_that_succeeded(): void
    {
        $this->makeDomain();
        $items = $this->boardItems(60);

        $itemCalls = 0;
        Http::fake([
            'https://api.monday.com/v2' => function ($request) use (&$itemCalls, $items) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'items_page')) {
                    return Http::response(['data' => ['boards' => [['id' => '123', 'items_page' => ['cursor' => null, 'items' => $items]]]]]);
                }

                if (str_contains($query, 'query Items')) {
                    $itemCalls++;

                    if ($itemCalls === 2) {
                        return Http::response(['errors' => [['message' => 'Complexity budget exceeded']]]);
                    }

                    $ids = json_decode($request->body(), true)['variables']['ids'] ?? [];
                    $fetched = collect($ids)->map(fn ($id): array => [
                        'id' => (string) $id,
                        'name' => 'Item '.$id,
                        'created_at' => null,
                        'updated_at' => null,
                        'column_values' => [],
                    ])->values()->all();

                    return Http::response(['data' => ['items' => $fetched]]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        $result = app(MondaySyncService::class)->syncDomain('equipment');

        // Batches are 25/25/10; the second one fails to fetch, so the first
        // (25) and third (10) still land while its 25 ids count as failed.
        $this->assertSame('ok', $result['status']);
        $this->assertSame(60, $result['new']);
        $this->assertSame(35, $result['imported']);
        $this->assertSame(25, $result['failed']);

        $this->assertSame(35, DynamicRow::query()->where('table_key', 'equipment')->count());
        $this->assertSame(35, MondaySyncedItem::query()->where('domain', 'equipment')->where('state', 'imported')->count());

        // The failed batch was never recorded — it stays a candidate.
        $this->assertSame(25, $result['new'] - MondaySyncedItem::query()->where('domain', 'equipment')->count());
    }

    public function test_when_every_batch_fails_the_sync_still_fails_loudly(): void
    {
        $this->makeDomain();
        $items = $this->boardItems(20);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) use ($items) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'items_page')) {
                    return Http::response(['data' => ['boards' => [['id' => '123', 'items_page' => ['cursor' => null, 'items' => $items]]]]]);
                }

                if (str_contains($query, 'query Items')) {
                    return Http::response(['errors' => [['message' => 'boom']]]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        $this->expectException(MondayApiException::class);

        app(MondaySyncService::class)->syncDomain('equipment');
    }

    public function test_grid_shows_the_newest_rows_first(): void
    {
        DynamicTableModel::create([
            'key' => 'equipment',
            'name' => 'Equipment',
            'monday_board_id' => '123',
            'created_by' => null,
        ]);

        $older = DynamicRow::query()->create([
            'table_key' => 'equipment',
            'name' => 'Older request',
            'source_system' => 'monday:equipment',
            'source_record_id' => '1',
        ]);
        $newer = DynamicRow::query()->create([
            'table_key' => 'equipment',
            'name' => 'Newer request',
            'source_system' => 'monday:equipment',
            'source_record_id' => '2',
        ]);

        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $html = Livewire::actingAs($user)
            ->test(DynamicTable::class, ['table' => 'equipment'])
            ->html();

        preg_match('/data-managed-table-payload>(.*?)<\/script>/s', $html, $m);
        $payload = json_decode($m[1] ?? '{}', true);
        $ids = array_column($payload['rows'] ?? [], 'id');

        $this->assertSame([$newer->id, $older->id], $ids, 'The grid must list the newest row first.');
    }
}
