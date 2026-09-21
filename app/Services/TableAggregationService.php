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
     * @return array{metrics: array<string, float|int>, datasets: array<string, array<int, array<string, mixed>>>}
     */
    public function summary(string $tableKey, ?string $region = null): array
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $scope = $region ?: 'all';

        return Cache::remember(
            "table-agg:v{$version}:{$tableKey}:{$scope}",
            300,
            fn (): array => $this->compute($tableKey, $region),
        );
    }

    /** Drop every cached aggregation (called by the import paths). */
    public static function invalidate(): void
    {
        Cache::increment(self::CACHE_VERSION_KEY);
    }

    private function compute(string $tableKey, ?string $region): array
    {
        try {
            return $this->computeOrFail($tableKey, $region);
        } catch (\Throwable $exception) {
            // A single bad source must never 500 a dashboard or the
            // visualization wizard: log the real cause and degrade to an
            // empty source (widgets render their empty state).
            report($exception);

            Log::error('tableAggregation.failed', [
                'table' => $tableKey,
                'region' => $region,
                'error' => $exception->getMessage(),
            ]);

            return ['metrics' => [], 'datasets' => []];
        }
    }

    private function computeOrFail(string $tableKey, ?string $region): array
    {
        return match ($tableKey) {
            'service-requests' => $this->serviceRequests($region),
            'technical-reports' => $this->technicalReports($region),
            'history-reports' => $this->historyReports($region),
            'personnel' => $this->personnel($region),
            'installed-products' => $this->installedProducts($region),
            // User-created (dynamic) tables are EAV; generic aggregation is a
            // follow-up. They still connect as sources, but expose no datasets
            // yet — never fall through to the Product Database.
            default => ['metrics' => [], 'datasets' => []],
        };
    }

    private function installedProducts(?string $region): array
    {
        $summary = app(ProductDashboardService::class)->summary($region, '12M');

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
        ];
    }

    private function serviceRequests(?string $region): array
    {
        $base = fn (): Builder => ServiceRequest::query()
            ->whereNull('archived_at')
            ->when($region, fn (Builder $query) => $query->where('region', $region));

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
        ];
    }

    private function technicalReports(?string $region): array
    {
        $base = fn (): Builder => TechnicalReport::query()->whereNull('archived_at');

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
        ];
    }

    private function historyReports(?string $region): array
    {
        $base = fn (): Builder => HistoricalTsmsReport::query()->whereNull('archived_at');

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
        ];
    }

    private function personnel(?string $region): array
    {
        $base = fn (): Builder => TechnicalPersonnel::query()
            ->whereNull('archived_at')
            ->when($region, fn (Builder $query) => $query->where('region', $region));

        return [
            'metrics' => [
                'rows' => $base()->count(),
            ],
            'datasets' => [
                'by_region' => $this->countsBy($base(), 'region'),
                'by_position' => $this->countsBy($base(), 'position'),
                'by_branch' => $this->countsBy($base(), 'branch'),
            ],
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
