<?php

namespace App\Services;

use App\Models\HistoricalTsmsReport;
use App\Models\TechnicalReport;
use Illuminate\Support\Carbon;

class TechnicalServiceAnalysisService
{
    public function summary(): array
    {
        $totalReports = TechnicalReport::query()->count();
        $completed = TechnicalReport::query()->whereNotNull('service_completed_at')->count();
        $withTsp = TechnicalReport::query()->whereNotNull('tsp_name')->where('tsp_name', '<>', '')->count();

        $byStatus = TechnicalReport::query()
            ->selectRaw('COALESCE(service_status, \'Unassigned\') as label, COUNT(*) as count')
            ->groupBy('service_status')
            ->orderByDesc('count')
            ->limit(8)
            ->get();

        $byTsp = TechnicalReport::query()
            ->selectRaw('tsp_name as key_name, MAX(COALESCE(tsp_display_name, tsp_name)) as label, COUNT(*) as count')
            ->whereNotNull('tsp_name')
            ->where('tsp_name', '<>', '')
            ->groupBy('tsp_name')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $byBrand = TechnicalReport::query()
            ->selectRaw('COALESCE(brand, \'Unknown\') as label, COUNT(*) as count')
            ->groupBy('brand')
            ->orderByDesc('count')
            ->limit(8)
            ->get();

        $avgRepair = (float) TechnicalReport::query()->whereNotNull('repair_time_hours')->average('repair_time_hours');
        $avgResponse = (float) TechnicalReport::query()->whereNotNull('response_time_hours')->average('response_time_hours');

        $trend = collect(range(6, 0))->map(function (int $daysAgo): array {
            $date = Carbon::today()->subDays($daysAgo);

            return [
                'day' => $date->format('D'),
                'value' => TechnicalReport::query()->whereDate('service_completed_at', $date)->count(),
            ];
        })->all();

        return [
            'metrics' => [
                ['label' => 'Technical reports', 'value' => number_format($totalReports), 'context' => 'imported reports', 'icon' => 'o-document-text', 'tone' => 'primary'],
                ['label' => 'Completed', 'value' => number_format($completed), 'context' => 'have a completion time', 'icon' => 'o-check-circle', 'tone' => 'success'],
                ['label' => 'Assigned TSP', 'value' => number_format($withTsp), 'context' => 'reports with TSP', 'icon' => 'o-users', 'tone' => 'info'],
                ['label' => 'Avg repair time', 'value' => number_format($avgRepair, 1).'h', 'context' => 'from report data', 'icon' => 'o-clock', 'tone' => 'warning'],
            ],
            'byStatus' => $byStatus->map(fn ($row): array => ['label' => $row->label, 'count' => $row->count])->all(),
            'byTsp' => $byTsp->map(fn ($row): array => ['label' => $row->label ?: $row->key_name, 'count' => $row->count])->all(),
            'byBrand' => $byBrand->map(fn ($row): array => ['label' => $row->label, 'count' => $row->count])->all(),
            'trend' => $trend,
            'trendTotal' => array_sum(array_column($trend, 'value')),
            'avgResponse' => $avgResponse,
        ];
    }
}
