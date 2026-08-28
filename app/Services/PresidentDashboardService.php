<?php

namespace App\Services;

use App\Models\Account;
use App\Models\HistoricalTsmsReport;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use Illuminate\Support\Carbon;

class PresidentDashboardService
{
    public const REGIONS = ['NCR', 'North Luzon', 'Visayas', 'Mindanao'];

    private const COMPLETED = ['completed', 'resolved', 'closed'];
    private const IN_PROGRESS = ['in-progress', 'in progress', 'for continuation'];
    private const OPEN = ['open', 'for escalation'];

    public function summary(?string $region = 'All regions'): array
    {
        $isAll = ! $region || $region === 'All regions';
        $scopeRegion = $isAll ? null : $region;

        $statusCounts = ServiceRequest::query()
            ->when($scopeRegion, fn ($q) => $q->where('region', $scopeRegion))
            ->selectRaw("COALESCE(NULLIF(group_status, ''), NULLIF(ticket_status, ''), 'Unassigned') as label, COUNT(*) as total")
            ->groupBy('label')
            ->orderByDesc('total')
            ->get();

        $totalRequests = (int) $statusCounts->sum('total');
        $statusTotal = max(1, $totalRequests);
        $statusSummary = $statusCounts->map(fn ($row): array => [
            'label' => $row->label,
            'count' => number_format((int) $row->total),
            'share' => number_format(((int) $row->total / $statusTotal) * 100, 1).'%',
            'tone' => $this->statusTone($row->label),
        ])->all();
        $completedRequests = (int) $statusCounts
            ->filter(fn ($row): bool => in_array(strtolower((string) $row->label), self::COMPLETED, true))
            ->sum('total');
        $completionRate = $totalRequests > 0 ? round(($completedRequests / $totalRequests) * 100, 1) : 0;

        $productQuery = Installation::query()
            ->when($scopeRegion, fn ($q) => $q->whereHas('account', fn ($q2) => $q2->where('region', $scopeRegion)));
        $totalProducts = (int) $productQuery->count();
        $activeProducts = (int) $productQuery->clone()->whereIn('device_status', ['Active', 'ACTIVE'])->count();
        $warrantyActive = (int) $productQuery->clone()->where('warranty_status', 'Yes')->count();

        $openRequests = $totalRequests - $completedRequests;
        $openRatio = $totalRequests > 0 ? round(($openRequests / $totalRequests) * 100, 1) : 0;
        $activeRatio = $totalProducts > 0 ? round(($activeProducts / $totalProducts) * 100, 1) : 0;
        $warrantyRatio = $totalProducts > 0 ? round(($warrantyActive / $totalProducts) * 100, 1) : 0;

        // Data-driven RAG thresholds (KnowledgeLib command-center model: measurable, not subjective)
        $ragCompletion = $completionRate >= 90 ? 'green' : ($completionRate >= 80 ? 'amber' : 'red');
        $ragOpen = $openRatio <= 10 ? 'green' : ($openRatio <= 20 ? 'amber' : 'red');
        $ragActive = $activeRatio >= 90 ? 'green' : ($activeRatio >= 75 ? 'amber' : 'red');
        $ragWarranty = $warrantyRatio >= 50 ? 'green' : ($warrantyRatio >= 30 ? 'amber' : 'red');

        $personnelScope = TechnicalPersonnel::query()
            ->where(function ($query): void {
                $query->where('position', 'like', '%Service%')
                    ->orWhere('position', 'like', '%Field%')
                    ->orWhere('position', 'like', '%TSP%');
            });
        $totalPersonnel = (int) $personnelScope->clone()->count();

        $regions = collect(self::REGIONS)->map(function (string $regionName) use ($scopeRegion): array {
            $productCount = Installation::query()
                ->whereHas('account', fn ($q) => $q->where('region', $regionName))
                ->count();
            $personnelCount = TechnicalPersonnel::query()
                ->where('region', $regionName)
                ->where(function ($query): void {
                    $query->where('position', 'like', '%Service%')
                        ->orWhere('position', 'like', '%Field%')
                        ->orWhere('position', 'like', '%TSP%');
                })
                ->count();
            $openCount = ServiceRequest::query()
                ->where('region', $regionName)
                ->whereNotIn('group_status', ['Completed', 'Resolved', 'Closed'])
                ->count();

            return [
                'region' => $regionName,
                'products' => (int) $productCount,
                'personnel' => (int) $personnelCount,
                'open_requests' => (int) $openCount,
                'attention' => $openCount >= 50,
            ];
        })->all();

        $regionMax = [
            'products' => max(1, (int) collect($regions)->max('products')),
            'personnel' => max(1, (int) collect($regions)->max('personnel')),
            'open_requests' => max(1, (int) collect($regions)->max('open_requests')),
        ];

        $requestTrend = collect(range(11, 0))->map(function (int $monthsAgo): array {
            $date = Carbon::today()->subMonths($monthsAgo);
            return [
                'label' => $date->format('M Y'),
                'short' => $date->format('M'),
                'count' => ServiceRequest::query()->whereMonth('created_at', $date->month)->whereYear('created_at', $date->year)->count(),
            ];
        })->all();

        $trendCounts = array_column($requestTrend, 'count');
        $mom = 0;
        if (count($trendCounts) >= 2) {
            $last = (int) end($trendCounts);
            $prev = (int) prev($trendCounts);
            $mom = $prev > 0 ? round((($last - $prev) / $prev) * 100, 1) : 0;
        }

        $productsByBrand = Installation::query()
            ->when($scopeRegion, fn ($q) => $q->whereHas('account', fn ($q2) => $q2->where('region', $scopeRegion)))
            ->whereNotNull('brand')->where('brand', '<>', '')
            ->selectRaw('brand, COUNT(*) as total')
            ->groupBy('brand')->orderByDesc('total')->limit(8)->get();

        $historyByYear = HistoricalTsmsReport::query()
            ->whereNotNull('response_timestamp')
            ->selectRaw("strftime('%Y', response_timestamp) as year, COUNT(*) as total")
            ->groupBy('year')->orderBy('year')->get();

        $historyByType = HistoricalTsmsReport::query()
            ->whereNotNull('service_type')->where('service_type', '<>', '')
            ->selectRaw('service_type, COUNT(*) as total')
            ->groupBy('service_type')->orderByDesc('total')->limit(6)->get();

        $personnelTable = (clone $personnelScope)
            ->select('name', 'position', 'branch', 'region')
            ->orderBy('name')->limit(20)->get();

        $historyTable = HistoricalTsmsReport::query()
            ->latest('response_timestamp')
            ->limit(12)
            ->get(['csr_number', 'account_name', 'service_type', 'status', 'tsp_name', 'branch', 'response_timestamp']);

        // Status macro-buckets for a clean donut (≤4 segments per data-viz guidance)
        $buckets = ['Completed' => 0, 'In progress' => 0, 'Open' => 0, 'Other' => 0];
        foreach ($statusCounts as $row) {
            $label = strtolower((string) $row->label);
            if (in_array($label, self::COMPLETED, true)) {
                $buckets['Completed'] += (int) $row->total;
            } elseif (in_array($label, self::IN_PROGRESS, true)) {
                $buckets['In progress'] += (int) $row->total;
            } elseif (in_array($label, self::OPEN, true)) {
                $buckets['Open'] += (int) $row->total;
            } else {
                $buckets['Other'] += (int) $row->total;
            }
        }
        $statusDonut = collect($buckets)
            ->filter(fn ($v) => $v > 0)
            ->map(function (int $value, string $label): array {
                $tone = match ($label) {
                    'Completed' => 'success',
                    'In progress' => 'warning',
                    'Open' => 'error',
                    default => 'neutral',
                };
                return ['label' => $label, 'value' => $value, 'tone' => $tone];
            })
            ->values()
            ->all();

        $requestArea = $this->areaPaths($requestTrend, 'count', 640, 120);
        $historyArea = $this->areaPaths($historyByYear->toArray(), 'total', 320, 120);

        // ---- Real ops visualizations inspired by the MCBTSI exec reference (no invented data) ----
        // Avg repair time (hours) from TechnicalReport.repair_time_hours
        $trScope = TechnicalReport::query()
            ->when($scopeRegion, fn ($q) => $q->whereHas('serviceRequest', fn ($q2) => $q2->where('region', $scopeRegion)));
        $avgRepairTime = (float) $trScope->clone()->whereNotNull('repair_time_hours')->avg('repair_time_hours');
        // Service requests by branch (top branches by report count) — branch lives on service_requests
        $byBranch = $trScope->clone()
            ->whereNotNull('service_request_id')
            ->join('service_requests', 'service_requests.id', '=', 'technical_reports.service_request_id')
            ->whereNotNull('service_requests.branch')->where('service_requests.branch', '<>', '')
            ->selectRaw('service_requests.branch as branch, COUNT(*) as total')
            ->groupBy('service_requests.branch')->orderByDesc('total')->limit(8)
            ->get()->map(fn ($row): array => ['label' => $row->branch, 'total' => (int) $row->total])->all();
        // Top TSPs by workload — show the real display name (source-grounded map), fall back to the anonymized ID
        $topTsps = $trScope->clone()
            ->whereNotNull('tsp_name')->where('tsp_name', '<>', '')
            ->selectRaw('tsp_name, MAX(COALESCE(tsp_display_name, tsp_name)) as display_name, COUNT(*) as total')
            ->groupBy('tsp_name')->orderByDesc('total')->limit(10)
            ->get()->map(fn ($row): array => ['label' => $row->display_name ?: $row->tsp_name, 'total' => (int) $row->total])->all();

        // Top 10 service request types (+ "Others" bucket for the long tail), like the MCBTSI reference
        $typeRows = ServiceRequest::query()
            ->when($scopeRegion, fn ($q) => $q->where('region', $scopeRegion))
            ->whereNotNull('service_type')->where('service_type', '<>', '')
            ->selectRaw('service_type, COUNT(*) as total')
            ->groupBy('service_type')->orderByDesc('total')->get();
        $typeTop = $typeRows->take(10);
        $typeOthers = (int) $typeRows->skip(10)->sum('total');
        $topTypes = $typeTop->map(fn ($row): array => ['label' => $row->service_type, 'total' => (int) $row->total])->all();
        if ($typeOthers > 0) {
            $topTypes[] = ['label' => 'Others', 'total' => $typeOthers];
        }

        // Top 10 equipment brands serviced (from ServiceRequest.brand = actually serviced units)
        $topBrands = ServiceRequest::query()
            ->when($scopeRegion, fn ($q) => $q->where('region', $scopeRegion))
            ->whereNotNull('brand')->where('brand', '<>', '')
            ->selectRaw('brand, COUNT(*) as total')
            ->groupBy('brand')->orderByDesc('total')->limit(10)
            ->get()->map(fn ($row): array => ['label' => $row->brand, 'total' => (int) $row->total])->all();

        // Attention / Risk view (command-center "what needs action"): regions with high open-request ratio.
        $attention = collect($regions)
            ->map(function (array $row): array {
                $regionTotal = max(1, $row['products'] + $row['personnel']);
                $ratio = round(($row['open_requests'] / $regionTotal) * 100, 1);
                $rag = $row['attention'] ? 'red' : ($row['open_requests'] >= 20 ? 'amber' : 'green');
                return [
                    'region' => $row['region'],
                    'open' => $row['open_requests'],
                    'ratio' => $ratio,
                    'rag' => $rag,
                ];
            })
            ->sortByDesc('open')
            ->values()
            ->all();

        // Data freshness: most recent record timestamp across live source tables.
        $fresh = max(
            (string) (ServiceRequest::query()->max('created_at') ?? ''),
            (string) (HistoricalTsmsReport::query()->max('response_timestamp') ?? ''),
            (string) (Installation::query()->max('updated_at') ?? ''),
        );
        $freshness = $fresh ? Carbon::parse($fresh)->format('M j, Y H:i') : 'live';

        $missingPms = (int) (clone $productQuery)->whereNull('pms_frequency')->count();

        $attentionSignals = [
            ['title' => number_format($openRequests).' service requests remain open', 'detail' => 'Review the current Service Requests table.', 'tone' => $openRequests > 0 ? 'warning' : 'success', 'icon' => 'o-clock'],
            ['title' => number_format($missingPms).' product database records have no PMS frequency', 'detail' => 'Superadmin can complete this field in Product Database.', 'tone' => 'warning', 'icon' => 'o-wrench-screwdriver'],
            ['title' => 'Source data status', 'detail' => 'Data as of '.$freshness, 'tone' => 'success', 'icon' => 'o-check-circle'],
        ];

        return [
            'selectedRegion' => $isAll ? 'All regions' : $region,
            'regionOptions' => ['All regions', ...self::REGIONS],
            'freshness' => $freshness,
            'statusSummary' => $statusSummary,
            'attentionSignals' => $attentionSignals,
            'kpis' => [
                ['label' => 'Product database', 'value' => $totalProducts, 'context' => $isAll ? 'all regions' : $region, 'tone' => 'primary', 'icon' => 'o-cube', 'rag' => 'green', 'href' => route('installed-products')],
                ['label' => 'Active products', 'value' => $activeProducts, 'context' => $activeRatio . '% of installed', 'tone' => 'success', 'icon' => 'o-check-circle', 'rag' => $ragActive],
                ['label' => 'Warranty covered', 'value' => $warrantyActive, 'context' => $warrantyRatio . '% of installed', 'tone' => 'info', 'icon' => 'o-shield-check', 'rag' => $ragWarranty],
                ['label' => 'Technical personnel', 'value' => $totalPersonnel, 'context' => 'service/field roles', 'tone' => 'info', 'icon' => 'o-users', 'rag' => 'green', 'href' => route('personnel')],
                ['label' => 'Service requests', 'value' => $totalRequests, 'context' => $openRequests . ' open (' . $openRatio . '%)', 'tone' => 'warning', 'icon' => 'o-inbox-stack', 'rag' => $ragOpen, 'spark' => $trendCounts, 'trend' => $mom, 'href' => route('service-requests')],
                ['label' => 'Completion rate', 'value' => $completionRate, 'suffix' => '%', 'context' => 'completed/resolved/closed', 'tone' => 'success', 'icon' => 'o-chart-bar', 'rag' => $ragCompletion],
                ['label' => 'Avg repair time', 'value' => $avgRepairTime, 'suffix' => 'h', 'context' => 'mean per technical report', 'tone' => 'info', 'icon' => 'o-clock', 'rag' => 'green'],
                ['label' => 'Historical TSMS', 'value' => HistoricalTsmsReport::query()->count(), 'context' => 'isolated history', 'tone' => 'warning', 'icon' => 'o-archive-box', 'rag' => 'green', 'href' => route('history-reports')],
            ],
            'statusCounts' => $statusCounts->map(fn ($row): array => ['label' => $row->label, 'total' => (int) $row->total])->all(),
            'statusTotal' => max(1, $totalRequests),
            'statusDonut' => $statusDonut,
            'completionRate' => $completionRate,
            'openRequests' => $openRequests,
            'openRatio' => $openRatio,
            'regions' => $regions,
            'attention' => $attention,
            'regionMax' => $regionMax,
            'requestTrend' => $requestTrend,
            'requestArea' => $requestArea,
            'requestMoM' => $mom,
            'productsByBrand' => $productsByBrand->map(fn ($row): array => ['label' => $row->brand, 'total' => (int) $row->total])->all(),
            'historyByYear' => $historyByYear->map(fn ($row): array => ['label' => $row->year ?: 'Unknown', 'total' => (int) $row->total])->all(),
            'historyByType' => $historyByType->map(fn ($row): array => ['label' => $row->service_type, 'total' => (int) $row->total])->all(),
            'historyArea' => $historyArea,
            'avgRepairTime' => $avgRepairTime,
            'byBranch' => $byBranch,
            'topTsps' => $topTsps,
            'topTypes' => $topTypes,
            'topBrands' => $topBrands,
            'personnel' => $personnelTable->map(fn ($row): array => ['name' => $row->name, 'position' => $row['position'], 'branch' => $row->branch, 'region' => $row->region])->all(),
            'historyRecords' => $historyTable->map(fn ($row): array => [
                'csr' => $row->csr_number,
                'account' => $row->account_name,
                'type' => $row->service_type,
                'status' => $row->status,
                'tsp' => $row->tsp_display_name ?: $row->tsp_name,
                'branch' => $row->branch,
                'date' => $row->response_timestamp?->format('Y-m-d'),
            ])->all(),
            'scopeNote' => $isAll ? 'All regions' : $region,
        ];
    }

    /**
     * Map a service-request status label to a semantic badge tone (semantic,
     * not just neutral). Covers the full canonical set so no status falls back
     * to an ambiguous gray.
     */
    private function statusTone(?string $label): string
    {
        $norm = strtolower(trim((string) $label));

        return match (true) {
            $norm === '' || $label === null => 'neutral',
            in_array($norm, ['completed', 'resolved', 'closed', 'done'], true) => 'success',
            in_array($norm, ['open', 'new', 'unassigned'], true) => 'info',
            in_array($norm, ['in-progress', 'in progress', 'ongoing', 'for continuation'], true) => 'warning',
            in_array($norm, ['rejected', 'cancelled', 'canceled', 'for escalation', 'escalated'], true) => 'danger',
            default => 'neutral',
        };
    }

    /**
     * Build SVG area + line path strings from a list of {valueKey} points.
     * Pure geometry — no data invented.
     */
    private function areaPaths(array $items, string $valueKey, int $w, int $h, int $pad = 6): array
    {
        $counts = array_values(array_column($items, $valueKey));
        $n = count($counts);
        if ($n < 2) {
            return ['area' => '', 'line' => '', 'dots' => []];
        }
        $max = max(1, (float) max($counts));
        $stepX = ($w - $pad * 2) / ($n - 1);
        $points = [];
        $dots = [];
        foreach ($counts as $i => $c) {
            $x = $pad + $i * $stepX;
            $y = $h - $pad - (($c / $max) * ($h - $pad * 2));
            $points[] = [round($x, 1), round($y, 1)];
            $dots[] = [round($x, 1), round($y, 1)];
        }
        $line = 'M '.implode(' L ', array_map(fn ($p) => "{$p[0]} {$p[1]}", $points));
        $area = "M {$pad},".($h - $pad)." L ".implode(' L ', array_map(fn ($p) => "{$p[0]} {$p[1]}", $points))." L ".($w - $pad).",".($h - $pad)." Z";

        return ['area' => $area, 'line' => $line, 'dots' => $dots];
    }
}
