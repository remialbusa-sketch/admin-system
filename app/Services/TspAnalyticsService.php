<?php

namespace App\Services;

use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use Illuminate\Support\Carbon;

class TspAnalyticsService
{
    private const BRANCH_TO_REGION = [
        'NCR' => 'NCR',
        'NLR1' => 'North Luzon', 'NLR2' => 'North Luzon', 'NLR3' => 'North Luzon', 'North Luzon' => 'North Luzon',
        'CEB' => 'Visayas', 'BAC' => 'Visayas', 'ILO' => 'Visayas', 'TAC' => 'Visayas',
        'DAV' => 'Mindanao', 'CDO' => 'Mindanao', 'ZAM' => 'Mindanao', 'SL' => 'Mindanao',
    ];

    private const REGIONS = ['NCR', 'North Luzon', 'Visayas', 'Mindanao'];

    public function summary(string $region = 'All regions'): array
    {
        $activeTspByRegion = TechnicalPersonnel::query()
            ->where(function ($query): void {
                $query->where('position', 'like', '%Service%')
                    ->orWhere('position', 'like', '%Field%')
                    ->orWhere('position', 'like', '%TSP%');
            })
            ->whereNotNull('region')
            ->get()
            ->groupBy('region')
            ->map(fn ($group) => $group->count())
            ->all();

        $totalActive = (int) array_sum($activeTspByRegion);

        $openByRegion = ServiceRequest::query()
            ->whereNotNull('branch')
            ->whereIn('ticket_status', ['OPEN', 'In-Progress', 'For Continuation', 'For Escalation'])
            ->get()
            ->groupBy(fn ($row) => self::BRANCH_TO_REGION[trim((string) $row->branch)] ?? 'Other')
            ->map(fn ($group) => $group->count())
            ->all();

        $regionalData = collect(self::REGIONS)->map(function (string $regionName) use ($activeTspByRegion, $openByRegion): array {
            $open = (int) ($openByRegion[$regionName] ?? 0);

            return [
                'region' => $regionName,
                'active' => (int) ($activeTspByRegion[$regionName] ?? 0),
                'open' => $open,
            ];
        })->all();

        $visibleRegions = $region === 'All regions'
            ? $regionalData
            : collect($regionalData)->where('region', $region)->values()->all();

        $activeTsp = (int) collect($visibleRegions)->sum('active');
        $openRecords = (int) collect($visibleRegions)->sum('open');

        $totalReports = TechnicalReport::query()->count();
        $completedReports = TechnicalReport::query()->where('service_status', 'Completed')->count();
        $resolutionRate = $totalReports > 0 ? round(($completedReports / $totalReports) * 100, 1) : 0;

        $trend = collect(range(3, 0))->map(function (int $weeksAgo) {
            $start = Carbon::today()->subWeeks($weeksAgo + 1);
            $end = Carbon::today()->subWeeks($weeksAgo);
            $completed = TechnicalReport::query()
                ->whereNotNull('service_completed_at')
                ->whereBetween('service_completed_at', [$start, $end])
                ->count();
            $sla = $completed > 0 ? min(100, round(($completed / max(1, $completed)) * 100)) : 0;

            return [
                'label' => $start->format('M d'),
                'resolved' => $completed,
                'sla' => $sla,
            ];
        })->all();

        $kpis = [
            ['label' => 'Active TSPs', 'value' => number_format($activeTsp), 'context' => 'company service/field personnel', 'icon' => 'o-users', 'tone' => 'primary'],
            ['label' => 'Open records', 'value' => number_format($openRecords), 'context' => 'in selected scope', 'icon' => 'o-inbox-stack', 'tone' => 'info'],
            ['label' => 'Resolution rate', 'value' => $resolutionRate.'%', 'context' => 'completed technical reports', 'icon' => 'o-shield-check', 'tone' => 'success'],
            ['label' => 'Total reports', 'value' => number_format($totalReports), 'context' => 'technical report records', 'icon' => 'o-document-text', 'tone' => 'warning'],
        ];

        return [
            'kpis' => $kpis,
            'regionalData' => $visibleRegions,
            'trend' => $trend,
            'totalActive' => $totalActive,
        ];
    }

    public function details(?string $tspName = null, ?string $from = null, ?string $to = null, ?string $branch = null): array
    {
        $query = TechnicalReport::query()
            ->whereNotNull('tsp_name')
            ->where('tsp_name', '<>', '');

        if ($tspName !== null && $tspName !== '' && $tspName !== 'All TSPs') {
            $query->where('tsp_name', $tspName);
        }

        if ($from !== null && $from !== '') {
            $query->whereDate('service_started_at', '>=', $from);
        }
        if ($to !== null && $to !== '') {
            $query->whereDate('service_started_at', '<=', $to);
        }

        if ($branch !== null && $branch !== '' && $branch !== 'All branches') {
            $requestIds = ServiceRequest::query()
                ->where('branch', $branch)
                ->pluck('id')
                ->all();
            $query->whereIn('service_request_id', $requestIds);
        }

        $totalReports = (clone $query)->count();
        $completed = (clone $query)->where('service_status', 'Completed')->count();
        $distinctTsp = (clone $query)->distinct()->count('tsp_name');
        $avgRepair = (clone $query)->whereNotNull('repair_time_hours')->avg('repair_time_hours');

        $kpis = [
            ['label' => 'Reports (filtered)', 'value' => number_format($totalReports), 'context' => 'matching filters', 'icon' => 'o-document-text', 'tone' => 'primary'],
            ['label' => 'Distinct TSPs', 'value' => number_format($distinctTsp), 'context' => 'in current scope', 'icon' => 'o-users', 'tone' => 'info'],
            ['label' => 'Completion rate', 'value' => ($totalReports > 0 ? round(($completed / $totalReports) * 100, 1) : 0).'%', 'context' => 'completed reports', 'icon' => 'o-shield-check', 'tone' => 'success'],
            ['label' => 'Avg repair time', 'value' => round((float) $avgRepair, 2).'h', 'context' => 'per report', 'icon' => 'o-clock', 'tone' => 'warning'],
        ];

        $top = (clone $query)
            ->selectRaw('tsp_name, COUNT(*) as reports, SUM(CASE WHEN service_status = ? THEN 1 ELSE 0 END) as completed, AVG(repair_time_hours) as avg_repair', ['Completed'])
            ->groupBy('tsp_name')
            ->orderByDesc('reports')
            ->limit(25)
            ->get()
            ->map(function ($row): array {
                $reports = (int) $row->reports;
                $completed = (int) $row->completed;

                return [
                    'tsp_name' => $row->tsp_name,
                    'reports' => $reports,
                    'completed' => $completed,
                    'completion_rate' => $reports > 0 ? round(($completed / $reports) * 100, 1) : 0,
                    'avg_repair' => round((float) $row->avg_repair, 2),
                ];
            })
            ->all();

        return [
            'kpis' => $kpis,
            'topTsp' => $top,
            'filteredReports' => $totalReports,
        ];
    }

    public function branchOptions(): array
    {
        return ServiceRequest::query()
            ->whereNotNull('branch')
            ->where('branch', '<>', '')
            ->distinct()
            ->orderBy('branch')
            ->pluck('branch')
            ->all();
    }

    public function tspOptions(): array
    {
        return TechnicalReport::query()
            ->whereNotNull('tsp_name')
            ->where('tsp_name', '<>', '')
            ->distinct()
            ->orderBy('tsp_name')
            ->pluck('tsp_name')
            ->all();
    }
}
