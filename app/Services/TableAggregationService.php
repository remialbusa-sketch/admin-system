<?php

namespace App\Services;

use App\Models\HistoricalTsmsReport;
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Per-table aggregation for dashboard data sources. Every table connected to
 * a dashboard (DashboardSource) resolves through here into the shape the
 * widget engine consumes: scalar metrics + label/value datasets.
 *
 * Results are cached per table+region behind a version key that any import
 * bumps (invalidate()), so a fresh workbook is visible immediately without
 * flushing unrelated caches.
 */
class TableAggregationService
{
    public const CACHE_VERSION_KEY = 'table-agg-version';

    private const COMPLETED = ['completed', 'resolved', 'closed', 'done', 'complete'];

    /**
     * @param  array<string, mixed>  $filters  branch/status/date_from/date_to
     * @return array{metrics: array<string, float|int>, datasets: array<string, array<int, array<string, mixed>>>}
     */
    public function summary(string $tableKey, ?string $region = null, array $filters = []): array
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $scope = $region ?: 'all';
        $filterHash = md5(json_encode($filters));

        return Cache::remember(
            "table-agg:v{$version}:{$tableKey}:{$scope}:{$filterHash}",
            300,
            fn (): array => $this->compute($tableKey, $region, $filters),
        );
    }

    /** Drop every cached aggregation (called by the import paths). */
    public static function invalidate(): void
    {
        Cache::increment(self::CACHE_VERSION_KEY);
    }

    private function compute(string $tableKey, ?string $region, array $filters = []): array
    {
        try {
            return $this->computeOrFail($tableKey, $region, $filters);
        } catch (\Throwable $exception) {
            // A single bad source must never 500 a dashboard or the
            // visualization wizard: log the real cause and degrade to an
            // empty source (widgets render their empty state).
            report($exception);

            Log::error('tableAggregation.failed', [
                'table' => $tableKey,
                'region' => $region,
                'filters' => $filters,
                'error' => $exception->getMessage(),
            ]);

            return ['metrics' => [], 'datasets' => []];
        }
    }

    private function computeOrFail(string $tableKey, ?string $region, array $filters = []): array
    {
        return match ($tableKey) {
            'service-requests' => $this->serviceRequests($region, $filters),
            'technical-reports' => $this->technicalReports($region, $filters),
            'history-reports' => $this->historyReports($region, $filters),
            'personnel' => $this->personnel($region, $filters),
            'installed-products' => $this->installedProducts($region, $filters),
            // User-created (dynamic) tables are EAV; generic aggregation is a
            // follow-up. They still connect as sources, but expose no datasets
            // yet — never fall through to the Product Database.
            default => ['metrics' => [], 'datasets' => []],
        };
    }

    private function installedProducts(?string $region, array $filters = []): array
    {
        $summary = app(ProductDashboardService::class)->summary($region, '12M', $filters);

        return [
            'metrics' => is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [],
            'datasets' => [
                'regions' => $summary['regions'] ?? [],
                'install_trend' => $summary['installTrend'] ?? [],
                'machine_types' => $summary['machineTypes'] ?? [],
                'top_accounts' => $summary['topAccounts'] ?? [],
                'fleet' => $summary['fleetDonut'] ?? [],
                'brands' => $summary['brandDonut'] ?? [],
            ],
            'filters' => $filters,
        ];
    }

    private function serviceRequests(?string $region, array $filters = []): array
    {
        $base = fn (): Builder => ServiceRequest::query()
            ->whereNull('archived_at')
            ->when($region, fn (Builder $query) => $query->where('region', $region))
            ->when($filters['branch'] ?? null, fn (Builder $q, $v) => $q->where('branch', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->whereRaw('lower(trim(group_status)) = ?', [strtolower(trim((string) $v))]))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->where('source_updated_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->where('source_updated_at', '<=', $v));

        $total = $base()->count();
        $byGroup = $this->countsBy($base(), 'group_status');
        $completed = $this->sumCompleted($byGroup);

        return [
            'metrics' => [
                'rows' => $total,
                'open' => max(0, $total - $completed),
                'completed' => $completed,
                'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0,
            ],
            'datasets' => [
                'by_group' => $byGroup,
                'by_ticket_status' => $this->countsBy($base(), 'ticket_status'),
                'by_branch' => $this->countsBy($base(), 'branch'),
                'by_region' => $this->countsBy($base(), 'region'),
                'by_type' => $this->countsBy($base(), 'request_type'),
                'by_brand' => $this->countsBy($base(), 'brand'),
                'by_month' => $this->monthly($base(), 'source_updated_at'),
            ],
            'filters' => $filters,
        ];
    }

    private function technicalReports(?string $region, array $filters = []): array
    {
        $base = fn (): Builder => TechnicalReport::query()
            ->whereNull('archived_at')
            // Technical reports carry no branch column; branch comes from the
            // linked service request.
            ->when($filters['branch'] ?? null, fn (Builder $q, $v) => $q->whereHas('serviceRequest', fn (Builder $r) => $r->where('branch', $v)))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->whereRaw('lower(trim(service_status)) = ?', [strtolower(trim((string) $v))]))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->where('service_completed_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->where('service_completed_at', '<=', $v));

        $total = $base()->count();
        $byStatus = $this->countsBy($base(), 'service_status');
        $completed = $this->sumCompleted($byStatus);
        $byTsp = $this->countsBy($base(), 'tsp_display_name');

        return [
            'metrics' => [
                'rows' => $total,
                'completed' => $completed,
                'avg_repair_hours' => round((float) $base()->whereNotNull('repair_time_hours')->avg('repair_time_hours'), 2),
                'avg_response_hours' => round((float) $base()->whereNotNull('response_time_hours')->avg('response_time_hours'), 2),
                'unassigned' => $base()->where(function (Builder $query): void {
                    $query->where(fn (Builder $q) => $q->whereNull('tsp_name')->orWhere('tsp_name', ''))
                        ->where(fn (Builder $q) => $q->whereNull('tsp_display_name')->orWhere('tsp_display_name', ''));
                })->count(),
            ],
            'datasets' => [
                'by_status' => $byStatus,
                'by_ticket_status' => $this->countsBy($base(), 'ticket_status'),
                'by_tsp' => $byTsp !== [] ? $byTsp : $this->countsBy($base(), 'tsp_name'),
                'by_brand' => $this->countsBy($base(), 'brand'),
                'by_machine_type' => $this->countsBy($base(), 'machine_type'),
                'by_month' => $this->monthly($base(), 'service_completed_at'),
            ],
            'filters' => $filters,
        ];
    }

    private function historyReports(?string $region, array $filters = []): array
    {
        $base = fn (): Builder => HistoricalTsmsReport::query()
            ->whereNull('archived_at')
            // Historical TSMS reports have no region column, so the region
            // scope cannot narrow them — only branch/status/date filters apply.
            ->when($filters['branch'] ?? null, fn (Builder $q, $v) => $q->where('branch', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->whereRaw('lower(trim(status)) = ?', [strtolower(trim((string) $v))]))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->where('response_timestamp', '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->where('response_timestamp', '<=', $v));

        return [
            'metrics' => [
                'rows' => $base()->count(),
            ],
            'datasets' => [
                'by_status' => $this->countsBy($base(), 'status'),
                'by_service_type' => $this->countsBy($base(), 'service_type'),
                'by_branch' => $this->countsBy($base(), 'branch'),
                'by_brand' => $this->countsBy($base(), 'brand'),
                'by_month' => $this->monthly($base(), 'response_timestamp'),
            ],
            'filters' => $filters,
        ];
    }

    private function personnel(?string $region, array $filters = []): array
    {
        $base = fn (): Builder => TechnicalPersonnel::query()
            ->whereNull('archived_at')
            ->when($region, fn (Builder $query) => $query->where('region', $region))
            ->when($filters['branch'] ?? null, fn (Builder $q, $v) => $q->where('branch', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->whereRaw('lower(trim(position)) = ?', [strtolower(trim((string) $v))]));

        return [
            'metrics' => [
                'rows' => $base()->count(),
            ],
            'datasets' => [
                'by_region' => $this->countsBy($base(), 'region'),
                'by_position' => $this->countsBy($base(), 'position'),
                'by_branch' => $this->countsBy($base(), 'branch'),
            ],
            'filters' => $filters,
        ];
    }

    /**
     * Group counts shaped for every dataset-driven widget: donut/bar/table
     * read "label"+"value"/"total", the line chart reads "count".
     *
     * @return array<int, array{label: string, value: int, total: int}>
     */
    private function countsBy(Builder $query, string $column, int $limit = 12): array
    {
        return $query
            ->selectRaw("{$column} as label, count(*) as total")
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'label' => (string) $row->label,
                'value' => (int) $row->total,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Monthly buckets from a date column. date() works on both SQLite and
     * MySQL, and the month rollup happens in PHP (portable, no SQL dialect).
     *
     * @return array<int, array{label: string, value: int, total: int, count: int}>
     */
    private function monthly(Builder $query, string $column, int $months = 12): array
    {
        $rows = $query
            ->selectRaw("date({$column}) as d, count(*) as total")
            ->whereNotNull($column)
            ->groupBy('d')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $day = (string) ($row->d ?? '');

            if (strlen($day) < 7) {
                continue;
            }

            $month = substr($day, 0, 7);
            $buckets[$month] = ($buckets[$month] ?? 0) + (int) $row->total;
        }

        ksort($buckets);

        return collect(array_slice($buckets, -$months, null, true))
            ->map(fn (int $count, string $month): array => [
                'label' => $month,
                'value' => $count,
                'total' => $count,
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{label: string, value: int}>  $rows
     */
    private function sumCompleted(array $rows): int
    {
        $completed = 0;

        foreach ($rows as $row) {
            if (in_array(strtolower($row['label']), self::COMPLETED, true)) {
                $completed += $row['value'];
            }
        }

        return $completed;
    }
}
