<?php

namespace App\Jobs;

use App\Models\DynamicTable;
use App\Models\MondaySyncedItem;
use App\Models\MondaySyncSetting;
use App\Services\MondayApiClient;
use App\Services\MondayItemMapper;
use App\Support\MondaySettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * M-W: refetch a single newly created monday.com item (from a create-item
 * webhook) and map it into its domain's table — a dynamic table or one of the
 * five core domain tables (resolved through monday_sync_settings.board_id, the
 * same source of truth the connect flow writes). Idempotent: the monday item
 * id is the upsert identity, so a duplicate webhook delivery is harmless.
 */
class MondaySyncItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public string $itemId,
        public string|int $boardId,
        public ?string $triggerUuid = null,
    ) {}

    public function handle(MondayApiClient $client, MondayItemMapper $mapper): void
    {
        // Resolve the connected domain for this board: canonical = the sync
        // setting row; the dynamic registry lookup is the legacy fallback.
        $setting = MondaySyncSetting::query()
            ->where('board_id', (string) $this->boardId)
            ->first();

        if (! $setting) {
            $table = DynamicTable::query()
                ->where('monday_board_id', (string) $this->boardId)
                ->first();

            if (! $table) {
                return; // not connected anywhere — nothing to import
            }

            $setting = MondaySyncSetting::forDomain($table->key);
        }

        if (! MondaySettings::enabled() || ! $setting->enabled) {
            return; // toggle/global off — skip
        }

        $items = $client->items([$this->itemId]);

        if ($items === []) {
            return;
        }

        $item = $items[0];
        $ok = $mapper->mapItem($setting->domain, $item);

        MondaySyncedItem::query()->updateOrCreate(
            ['domain' => $setting->domain, 'item_id' => (string) $this->itemId],
            ['state' => $ok ? 'imported' : 'failed', 'last_seen_at' => now()],
        );
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
