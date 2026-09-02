<?php

namespace App\Console\Commands;

use App\Models\DynamicTable;
use App\Models\MondaySyncSetting;
use App\Services\MondaySyncService;
use Illuminate\Console\Command;
use Throwable;

class MondaySyncAllCommand extends Command
{
    protected $signature = 'monday:sync-all {--dry-run : report without recording}';

    protected $description = 'Run the new-item pull for every user-created table that has a connected board and live-pull toggle on';

    public function handle(MondaySyncService $service): int
    {
        if (! config('monday.enabled', false)) {
            $this->warn('monday sync is disabled globally (MONDAY_SYNC_ENABLED=false). Nothing to do.');

            return self::SUCCESS;
        }

        $domains = MondaySyncSetting::query()
            ->where('enabled', true)
            ->whereNotNull('board_id')
            ->pluck('domain');

        // Only domains that still have a real dynamic table (tables can be
        // created/deleted; a stale setting row shouldn't error the run).
        $valid = array_intersect(
            $domains->all(),
            DynamicTable::query()->pluck('key')->all(),
        );

        if ($valid === []) {
            $this->info('No connected tables with live-pull on.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $exit = self::SUCCESS;

        foreach ($valid as $domain) {
            try {
                $result = $service->syncDomain((string) $domain, $dryRun);

                if ($result['status'] === 'error') {
                    $this->error("[{$domain}] ".($result['message'] ?? 'error'));
                    $exit = self::FAILURE;

                    continue;
                }

                $new = $result['new'] ?? 0;
                $this->info("[{$domain}] ".($dryRun ? ($new.' new item(s) to import') : ($new.' new item(s) imported'))." (board {$result['board_id']}).");
            } catch (Throwable $exception) {
                $this->error("[{$domain}] sync failed: ".$exception->getMessage());
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
