<?php

namespace App\Console\Commands;

use App\Models\MondaySyncSetting;
use App\Models\TechnicalReport;
use App\Services\MondayApiClient;
use App\Services\TableAggregationService;
use App\Services\TechnicalServiceAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repairs technical reports whose REFERENCE # holds the monday item id: the
 * board's item_id echo column ("Reference Number") was mapped to the domain
 * column and overwrote the item name the title write had stored. The original
 * value is the board item name — the report's real reference.
 */
class RepairReportReferences extends Command
{
    protected $signature = 'monday:repair-report-references {--apply : Apply repairs; default only reports counts}';

    protected $description = 'Restore technical-report REFERENCE # values that the board item-id echo column overwrote';

    public function handle(MondayApiClient $client): int
    {
        $apply = (bool) $this->option('apply');
        $setting = MondaySyncSetting::query()->where('domain', 'technical-reports')->first();
        $boardId = (int) ($setting?->board_id ?? 0);

        $query = TechnicalReport::query()
            ->where('source_system', 'monday:technical-reports')
            ->whereColumn('reference_number', 'source_record_id');

        $total = (clone $query)->count();
        $names = $this->boardNames($client, $boardId);
        $fromName = 0;
        $fromServiceNumber = 0;
        $skipped = 0;

        DB::transaction(function () use ($apply, $names, $query, &$fromName, &$fromServiceNumber, &$skipped): void {
            $query->chunkById(250, function ($reports) use ($apply, $names, &$fromName, &$fromServiceNumber, &$skipped): void {
                foreach ($reports as $report) {
                    $name = trim((string) ($names[(string) $report->source_record_id] ?? ''));
                    $serviceNumber = trim((string) $report->service_request_number);
                    $target = $name !== '' ? $name : $serviceNumber;

                    if ($target === '') {
                        $skipped++;

                        continue;
                    }

                    if ($name !== '') {
                        $fromName++;
                    } else {
                        $fromServiceNumber++;
                    }

                    if ($apply) {
                        $report->forceFill(['reference_number' => mb_substr($target, 0, 255)])->save();
                    }
                }
            });
        });

        $unmapped = $apply ? $this->stripEchoSources($setting, $this->itemIdSources($client, $boardId)) : 0;

        if ($apply && $fromName + $fromServiceNumber > 0) {
            TableAggregationService::invalidate();
            foreach (TechnicalServiceAnalysisService::PERIODS as $days) {
                Cache::forget('tsa:summary:'.$days);
            }
        }

        $this->info(($apply ? 'Applied' : 'Dry run').": {$total} reports hold the monday item id as REFERENCE #; {$fromName} restored from the board item name, {$fromServiceNumber} fall back to the service request number, {$skipped} have neither.");

        if ($unmapped > 0) {
            $this->info("{$unmapped} item-id echo source(s) removed from the saved core field map.");
        }

        return self::SUCCESS;
    }

    /**
     * Current board item names keyed by item id — the value the title write
     * stored before the echo column overwrote it.
     *
     * @return array<string, string>
     */
    private function boardNames(MondayApiClient $client, int $boardId): array
    {
        if ($boardId <= 0 || ! $client->configured()) {
            return [];
        }

        $names = [];
        $cursor = null;

        try {
            do {
                $page = $client->itemsPage($boardId, $cursor);

                foreach ($page['items'] ?? [] as $item) {
                    $names[(string) ($item['id'] ?? '')] = (string) ($item['name'] ?? '');
                }

                $cursor = $page['cursor'] ?? null;
            } while ($cursor !== null && $cursor !== '');
        } catch (Throwable $exception) {
            $this->warn('Board item names unavailable ('.$exception->getMessage().'); falling back to the service request number.');

            return [];
        }

        return $names;
    }

    /**
     * Board columns that only echo the item's own id, keyed by column id.
     *
     * @return array<int, string>
     */
    private function itemIdSources(MondayApiClient $client, int $boardId): array
    {
        if ($boardId <= 0 || ! $client->configured()) {
            return [];
        }

        try {
            $sources = [];

            foreach ($client->boardColumns($boardId) as $column) {
                if ((string) ($column['type'] ?? '') === 'item_id' && (string) ($column['id'] ?? '') !== '') {
                    $sources[] = (string) $column['id'];
                }
            }
        } catch (Throwable $exception) {
            $this->warn('Board columns unavailable ('.$exception->getMessage().'); the saved core field map stays unchanged.');

            return [];
        }

        return $sources;
    }

    /**
     * Drop echo sources from the saved core map so the mapping editor no
     * longer carries a pair the mapper now ignores. No mapping_revision bump:
     * the apply-time guard already skipped these sources, so no row changed
     * because of this edit and a full replay would only waste quota.
     *
     * @param  array<int, string>  $itemIdSources
     */
    private function stripEchoSources(?MondaySyncSetting $setting, array $itemIdSources): int
    {
        if (! $setting || $itemIdSources === []) {
            return 0;
        }

        $core = (array) ($setting->core_field_map ?? []);
        $cleaned = array_diff_key($core, array_flip($itemIdSources));

        if ($cleaned === $core) {
            return 0;
        }

        $setting->core_field_map = $cleaned;
        $setting->save();

        return count($core) - count($cleaned);
    }
}
