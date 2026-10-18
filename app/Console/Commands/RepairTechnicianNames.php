<?php

namespace App\Console\Commands;

use App\Models\TechnicalReport;
use App\Services\TableAggregationService;
use App\Services\TechnicalServiceAnalysisService;
use App\Support\PersonName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RepairTechnicianNames extends Command
{
    protected $signature = 'monday:repair-technicians {--apply : Apply repairs; default only reports counts}';

    protected $description = 'Repair attachment URLs incorrectly imported into technician fields; retain original raw data';

    public function handle(): int
    {
        $repaired = 0;
        $unassigned = 0;
        $apply = (bool) $this->option('apply');
        DB::transaction(function () use ($apply, &$repaired, &$unassigned): void {
            TechnicalReport::query()->orderBy('id')->chunkById(250, function ($reports) use ($apply, &$repaired, &$unassigned): void {
                foreach ($reports as $report) {
                    $idBad = $report->tsp_name && PersonName::clean($report->tsp_name) === null;
                    $displayBad = $report->tsp_display_name && PersonName::clean($report->tsp_display_name) === null;
                    if (! $idBad && ! $displayBad) {
                        continue;
                    }
                    $raw = $report->raw_data ?? [];
                    $id = PersonName::clean($report->tsp_name) ?? PersonName::clean($raw['tsp_name'] ?? null);
                    $display = PersonName::clean($report->tsp_display_name) ?? PersonName::clean($raw['tsp'] ?? null);
                    $values = PersonName::technician($id, $display);
                    $repaired++;
                    $unassigned += $values['tsp_name'] === null ? 1 : 0;
                    if ($apply) {
                        $report->update($values);
                    }
                }
            });
        });
        if ($apply && $repaired > 0) {
            TableAggregationService::invalidate();
            foreach (TechnicalServiceAnalysisService::PERIODS as $days) {
                Cache::forget('tsa:summary:'.$days);
            }
        }
        $this->info(($apply ? 'Applied' : 'Dry run').": {$repaired} reports need repair; {$unassigned} have no recoverable technician and become unassigned. Raw source data is retained.");

        return self::SUCCESS;
    }
}
