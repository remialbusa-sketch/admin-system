<?php

namespace App\Console\Commands;

use App\Services\MondaySyncService;
use Illuminate\Console\Command;
use Throwable;

class MondaySyncCommand extends Command
{
    protected $signature = 'monday:sync {domain : the dynamic table key / domain to sync (must have a connected board and live-pull toggle on)} {--dry-run : report new items without recording them}';

    protected $description = 'Pull newly created monday.com items for one connected board into its dynamic table';

    public function handle(MondaySyncService $service): int
    {
        $domain = (string) $this->argument('domain');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $service->syncDomain($domain, $dryRun);
        } catch (Throwable $exception) {
            $this->error('Sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        match ($result['status']) {
            'disabled' => $this->warn($result['message'] ?? 'Disabled.'),
            'error' => $this->error($result['message'] ?? 'Error.'),
            default => $this->report($result, $dryRun),
        };

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }

    private function report(array $result, bool $dryRun): void
    {
        $this->info('Synced '.($result['seen'] ?? 0)." known item(s) on board [{$result['board_id']}].");

        if ($dryRun) {
            $this->info('Dry run — '.($result['new'] ?? 0).' new item(s) would be imported (not recorded):');

            foreach ($result['new_items'] as $item) {
                $this->line('  - #'.$item['id'].' '.($item['name'] ?? '(untitled)'));
            }

            return;
        }

        $this->info(($result['new'] ?? 0).' new item(s) discovered and marked for import.');
    }
}
