<?php

namespace App\Console\Commands;

use App\Models\Installation;
use App\Models\MondaySyncSetting;
use App\Services\ProductColumnRepair;
use App\Services\ProductDashboardService;
use App\Services\TableAggregationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RepairProductColumns extends Command
{
    protected $signature = 'monday:repair-products {--apply : Apply the reviewed repair; default reports counts only}';

    protected $description = 'Restore missing Product Database fields from stored source columns, preserving existing edits';

    public function handle(ProductColumnRepair $repair): int
    {
        $apply = (bool) $this->option('apply');
        $columns = $repair->columns();
        $setting = MondaySyncSetting::where('domain', 'installed-products')->first();
        $counts = ['products' => 0, 'fields' => 0, 'monday' => 0, 'imported' => 0, 'conflicts' => 0, 'invalid_dates' => 0];
        DB::transaction(function () use ($apply, $repair, $columns, $setting, &$counts): void {
            $query = Installation::whereIn('source_system', ['monday:installed-products', 'product_database']);
            if ($apply) {
                $query->lockForUpdate();
            }
            $query->chunkById(250, function ($rows) use ($apply, $repair, $columns, $setting, &$counts): void {
                $cells = $repair->cells($rows, $columns);
                foreach ($rows as $row) {
                    $plan = $repair->plan($row, $columns, $cells->get($row->id, collect()), $setting);
                    $counts['conflicts'] += $plan['conflicts'];
                    $counts['invalid_dates'] += $plan['invalid'];
                    if ($plan['changes'] === []) {
                        continue;
                    }
                    $counts['products']++;
                    $counts['fields'] += count($plan['changes']);
                    $counts[$row->source_system === 'product_database' ? 'imported' : 'monday']++;
                    if ($apply) {
                        $row->update($plan['changes']);
                    }
                }
            });
            if ($apply && $counts['monday'] > 0 && $setting) {
                // Old records were imported at the same revision as the new
                // mapper. Queue one replay if the owner later enables sync.
                $setting->increment('mapping_revision');
            }
        });
        if ($apply && $counts['products'] > 0) {
            TableAggregationService::invalidate();
            foreach ([...ProductDashboardService::REGIONS, 'all'] as $scope) {
                foreach (ProductDashboardService::PERIODS as $months) {
                    Cache::forget('product:summary:'.$scope.':'.$months);
                    Cache::forget('product:summary:'.$scope.':'.$months.':'.md5(json_encode([])));
                }
            }
        }
        $this->info(($apply ? 'Applied' : 'Dry run').': '.$counts['products'].' products, '.$counts['fields'].' fields; '.$counts['monday'].' monday rows, '.$counts['imported'].' imported rows.');
        $this->line($counts['conflicts'].' conflicting values preserved; '.$counts['invalid_dates'].' invalid source dates skipped.');

        return self::SUCCESS;
    }
}
