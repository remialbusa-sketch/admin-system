<?php

namespace App\Services;

use App\Models\DynamicTable;
use App\Models\MondaySyncedItem;
use App\Models\MondaySyncSetting;
use Illuminate\Support\Collection;

/**
 * New-item discovery for the monday.com integration (A7) + live write (M-DC).
 * "New" = an item id we have not recorded in monday_synced_items yet. Each run
 * reads the board's full id list (cheap, paginated), diffs against what we've
 * seen, refetches only the unseen ids, records them, then maps them into the
 * domain's dynamic table (if one exists) via MondayItemMapper. Idempotent and
 * toggle-aware: if the integration is globally disabled, or the domain's
 * setting is off, or no board is connected, nothing is fetched and no quota is
 * spent.
 */
class MondaySyncService
{
    public function __construct(protected MondayApiClient $client) {}

    /**
     * Discover + import new items on a connected board for one domain.
     *
     * @return array{status: string, board_id: ?string, new_items: Collection<int, array<string, mixed>>, seen: int, new?: int, imported?: int, failed?: int, message?: string}
     */
    public function syncDomain(string $domain, bool $dryRun = false): array
    {
        if (! config('monday.enabled', false)) {
            return ['status' => 'disabled', 'board_id' => null, 'new_items' => collect(), 'seen' => 0, 'message' => 'monday sync disabled globally (MONDAY_SYNC_ENABLED).'];
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
        $table = DynamicTable::query()->where('key', $domain)->first();

        // No dynamic table to write into (or not connected to it) -> nothing to import.
        if (! $table || ! $table->monday_board_id) {
            return ['status' => 'disabled', 'board_id' => $setting->board_id, 'new_items' => collect(), 'seen' => 0, 'message' => "Domain [{$domain}] has no dynamic table / connected board. Run connect first."];
        }

        // A connected table must have a field map (its columns auto-mapped from
        // the board). Without one, the board isn't ready to import into — treat
        // it as not-yet-connected rather than retry-looping per-item failures.
        if (empty($table->monday_field_map)) {
            return ['status' => 'disabled', 'board_id' => $setting->board_id, 'new_items' => collect(), 'seen' => 0, 'message' => "Domain [{$domain}] is connected but has no columns mapped yet. Reconnect (auto-map) first."];
        }

        // 1. Read the board's full id list (lightweight; no column values).
        $allIds = collect();
        $cursor = null;

        do {
            $page = $this->client->itemsPage($boardId, $cursor);
            $allIds = $allIds->merge(collect($page['items'])->pluck('id'));
            $cursor = $page['cursor'] ?: null;
        } while ($cursor !== null);

        $allIds = $allIds->unique()->values();

        // 2. Diff against what we've already successfully imported. Failed
        //    items stay candidates so the next run retries them (never silently
        //    lost — see the sync semantics in the plan §A5).
        $doneIds = MondaySyncedItem::query()
            ->where('domain', $domain)
            ->where('state', 'imported')
            ->whereIn('item_id', $allIds)
            ->pluck('item_id')
            ->flip();

        $candidateIds = $allIds->reject(fn (string $id): bool => $doneIds->has($id))->map('strval')->values();

        // 3. Refetch candidates (batched, as strings — monday accepts string
        //    ids and they avoid any integer-coercion surprises).
        $items = collect();
        foreach ($candidateIds->chunk(100) as $chunk) {
            $items = $items->merge($this->client->items($chunk->all()));
        }

        // 4. Map items into the dynamic table. Only successfully mapped items
        //    are recorded as 'imported'; failures stay candidates for retry.
        $mapper = app(MondayItemMapper::class);
        $imported = 0;
        $failed = 0;

        // Dry-run is report-only: nothing is mapped or recorded.
        if (! $dryRun) {
            foreach ($items as $item) {
                $itemId = (string) ($item['id'] ?? '');
                $ok = $mapper->mapItem($table, $item);

                $ok ? $imported++ : $failed++;

                MondaySyncedItem::query()->updateOrCreate(
                    ['domain' => $domain, 'item_id' => $itemId],
                    ['state' => $ok ? 'imported' : 'failed', 'last_seen_at' => now()],
                );
            }
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
