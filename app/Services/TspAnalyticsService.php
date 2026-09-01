<?php

namespace App\Services;

use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use Illuminate\Support\Carbon;

class TspAnalyticsService
{
    private const REGIONS = ['NCR', 'North Luzon', 'Visayas', 'Mindanao'];

    public function summary(string $region = 'All regions'): array
    {
        // Counts come straight from the canonical columns the importer writes
        // (personnel.region, service_requests.region + normalized group_status)
        // instead of mapping branches in PHP with an exact-case lookup that
        // silently dropped unknown branches into 'Other'.
        $activeTspByRegion = TechnicalPersonnel::query()
            ->where(function ($query): void {
                $query->where('position', 'like', '%Service%')
                    ->orWhere('position', 'like', '%Field%')
                    ->orWhere('position', 'like', '%TSP%');
            })
            ->whereNotNull('region')
            ->selectRaw('region, COUNT(*) as total')
            ->groupBy('region')
            ->pluck('total', 'region')
            ->all();

        $totalActive = (int) array_sum($activeTspByRegion);

        $openByRegion = ServiceRequest::query()
            ->whereNotNull('region')
            ->whereIn('group_status', ['Open', 'In-Progress', 'For Continuation', 'For Escalation'])
            ->selectRaw('region, COUNT(*) as total')
            ->groupBy('region')
            ->pluck('total', 'region')
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

            return [
                'label' => $start->format('M d'),
                'resolved' => $completed,
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
            ->selectRaw("tsp_name, MAX(COALESCE(NULLIF(tsp_display_name, ''), tsp_name)) as display_name, COUNT(*) as reports, SUM(CASE WHEN service_status = ? THEN 1 ELSE 0 END) as completed, AVG(repair_time_hours) as avg_repair", ['Completed'])
            ->groupBy('tsp_name')
            ->orderByDesc('reports')
            ->limit(25)
            ->get()
            ->map(function ($row): array {
                $reports = (int) $row->reports;
                $completed = (int) $row->completed;

                return [
                    // Real display name; the raw tsp_name is a workbook ID
                    // (person-XXXX…) that means nothing to people.
                    'tsp_name' => $row->display_name ?: $row->tsp_name,
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
        // value = the raw tsp_name (workbook ID) used for filtering;
        // label = the real display name so the dropdown reads like a roster.
        return TechnicalReport::query()
            ->whereNotNull('tsp_name')->where('tsp_name', '<>', '')
            ->selectRaw("tsp_name, MAX(COALESCE(NULLIF(tsp_display_name, ''), tsp_name)) as label")
            ->groupBy('tsp_name')
            ->orderBy('label')
            ->pluck('label', 'tsp_name')
            ->all();
    }
}
