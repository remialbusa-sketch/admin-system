<?php

namespace App\Console\Commands;

use App\Models\DynamicTable;
use App\Models\MondaySyncSetting;
use App\Services\MondaySyncService;
use App\Support\MondayCoreTargets;
use App\Support\MondaySettings;
use Illuminate\Console\Command;
use Throwable;

class MondaySyncAllCommand extends Command
{
    protected $signature = 'monday:sync-all {--dry-run : report without recording}';

    protected $description = 'Run the new-item pull for every connected table (core and dynamic) that has live-pull toggle on';

    public function handle(MondaySyncService $service): int
    {
        if (! MondaySettings::enabled()) {
            $this->warn('monday sync is disabled globally (Settings → monday.com). Nothing to do.');

            return self::SUCCESS;
        }

        $domains = MondaySyncSetting::query()
            ->where('enabled', true)
            ->whereNotNull('board_id')
            ->pluck('domain');

        // Only domains that still have a real table behind them (dynamic
        // tables can be deleted; a stale setting row shouldn't error the run).
        // Core domains are always resolvable — see MondayCoreTargets.
        $dynamicKeys = DynamicTable::query()->pluck('key')->all();
        $valid = $domains
            ->filter(fn (string $domain): bool => in_array($domain, $dynamicKeys, true) || MondayCoreTargets::has($domain))
            ->values()
            ->all();

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
