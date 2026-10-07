<?php

namespace App\Services;

use App\Models\DynamicTable;
use App\Models\MondaySyncedItem;
use App\Models\MondaySyncSetting;
use App\Support\MondayCoreTargets;
use App\Support\MondaySettings;
use Illuminate\Support\Collection;
use Throwable;

/**
 * New-item discovery for the monday.com integration (A7) + live write (M-DC).
 * "New" = an item id we have not recorded in monday_synced_items yet. Each run
 * reads the board's full item list (cheap, paginated), diffs against what we've
 * seen, refetches only the unseen ids — OLDEST first, one small JSON-array
 * batch at a time (a failed batch never discards the others' work) — records
 * them, then maps them into the domain — a dynamic table (DynamicRow) or a
 * core domain table (fixed model, see MondayCoreTargets) — via
 * MondayItemMapper. Idempotent and toggle-aware: if the integration is
 * globally disabled (Settings → monday.com), the domain's setting is off, or
 * no board is connected, nothing is fetched and no quota is spent.
 */
class MondaySyncService
{
    /**
     * Ids per items() call. monday accepts up to 500; 50 keeps each response
     * small even for wide boards while staying at a handful of requests.
     */
    public const BATCH_SIZE = 50;

    public function __construct(protected MondayApiClient $client) {}

    /**
     * Discover + import new items on a connected board for one domain.
     *
     * @return array{status: string, board_id: ?string, new_items: Collection<int, array<string, mixed>>, seen: int, new?: int, imported?: int, failed?: int, message?: string}
     */
    public function syncDomain(string $domain, bool $dryRun = false): array
    {
        if (! MondaySettings::enabled()) {
            return ['status' => 'disabled', 'board_id' => null, 'new_items' => collect(), 'seen' => 0, 'message' => 'monday sync disabled globally (Settings → monday.com).'];
        }

        $setting = MondaySyncSetting::forDomain($domain);

        if (! $setting->enabled) {
            return ['status' => 'disabled', 'board_id' => $setting->board_id, 'new_items' => collect(), 'seen' => 0, 'message' => "Live pull is off for domain [{$domain}]."];
        }

        if (! $setting->board_id) {
            return ['status' => 'disabled', 'board_id' => null, 'new_items' => collect(), 'seen' => 0, 'message' => "Domain [{$domain}] has no connected board."];
        }

        if (! $this->client->configured()) {
            return ['status' => 'error', 'board_id' => $setting->board_id, 'new_items' => collect(), 'seen' => 0, 'failed' => true, 'message' => 'monday.com API token is not configured.'];
        }

        $boardId = (int) $setting->board_id;
        $dynamic = DynamicTable::query()->where('key', $domain)->first();

        // Domain must exist: a dynamic table or one of the five core tables.
        if (! $dynamic && ! MondayCoreTargets::has($domain)) {
            return ['status' => 'disabled', 'board_id' => $setting->board_id, 'new_items' => collect(), 'seen' => 0, 'message' => "Domain [{$domain}] has no matching table. Run connect first."];
        }

        // A connected table must have a field map (columns auto-mapped from
        // the board). The canonical home is monday_sync_settings.field_map;
        // pre-field_map connections only have the dynamic registry column.
        $fieldMap = $setting->field_map ?: ($dynamic->monday_field_map ?? []);
        if (empty($fieldMap)) {
            return ['status' => 'disabled', 'board_id' => $setting->board_id, 'new_items' => collect(), 'seen' => 0, 'message' => "Domain [{$domain}] is connected but has no columns mapped yet. Reconnect (auto-map) first."];
        }

        // 1. Read the board's full item list (lightweight; no column values).
        $allItems = collect();
        $cursor = null;

        do {
            $page = $this->client->itemsPage($boardId, $cursor);
            $allItems = $allItems->merge(collect($page['items']));
            $cursor = $page['cursor'] ?: null;
        } while ($cursor !== null);

        $allItems = $allItems->unique('id')->values();
        $allIds = $allItems->pluck('id');

        // 2. Diff against what we've already successfully imported. Failed
        //    items stay candidates so the next run retries them (never silently
        //    lost — see the sync semantics in the plan §A5).
        $doneIds = MondaySyncedItem::query()
            ->where('domain', $domain)
            ->where('state', 'imported')
            ->whereIn('item_id', $allIds)
            ->pluck('item_id')
            ->flip();

        // Candidates in board-age order — OLDEST first — so a run that dies
        // halfway leaves the oldest, most settled records in place, and the
        // grid's default newest-first order fills in from the top as the
        // newer batches land.
        $candidateIds = $allItems
            ->reject(fn (array $item): bool => $doneIds->has((string) ($item['id'] ?? '')))
            ->sortBy(fn (array $item): array => [
                ($item['created_at'] ?? '') === '' ? '9999-12-31T23:59:59+00:00' : (string) $item['created_at'],
                (float) ($item['id'] ?? 0),
            ])
            ->map(fn (array $item): string => (string) $item['id'])
            ->values();

        // 3+4. Fetch + import ONE BATCH AT A TIME: each batch is fetched,
        //    mapped and recorded on its own, so a failed batch never discards
        //    the others' work. Ids travel as a JSON array of at most
        //    BATCH_SIZE entries (monday caps an items() call at 500; the
        //    wire shape is guarded by MondayApiClient::wireVariables).
        $mapper = app(MondayItemMapper::class);
        $items = collect();
        $imported = 0;
        $failed = 0;
        $batchesOk = 0;
        $firstError = null;

        foreach ($candidateIds->chunk(self::BATCH_SIZE) as $chunk) {
            $ids = $chunk->values()->all();

            try {
                $batchItems = collect($this->client->items($ids));
            } catch (MondayRateLimitException $exception) {
                // Global quota — the scheduler honours retryAfter; further
                // batches in this run would only burn more budget.
                throw $exception;
            } catch (Throwable $exception) {
                $firstError ??= $exception;
                $failed += count($ids);

                continue;
            }

            $batchesOk++;
            $items = $items->merge($batchItems);

            if ($dryRun) {
                continue; // report-only: fetched, never mapped or recorded
            }

            foreach ($batchItems as $item) {
                $itemId = (string) ($item['id'] ?? '');
                $ok = $mapper->mapItem($domain, $item);

                $ok ? $imported++ : $failed++;

                MondaySyncedItem::query()->updateOrCreate(
                    ['domain' => $domain, 'item_id' => $itemId],
                    ['state' => $ok ? 'imported' : 'failed', 'last_seen_at' => now()],
                );
            }
        }

        // Every batch failed (dead token, payload error, network down): fail
        // loudly instead of reporting a "done" sync that imported nothing.
        if ($batchesOk === 0 && $firstError !== null) {
            throw $firstError;
        }

        if (! $dryRun) {
            $setting->update(['last_synced_at' => now(), 'last_item_id_seen' => $candidateIds->last()]);
        }

        return [
            'status' => 'ok',
            'board_id' => $setting->board_id,
            'new_items' => $items,
            'seen' => $allIds->count(),
            'new' => $candidateIds->count(),
            'imported' => $imported,
            'failed' => $failed,
        ];
    }
}
