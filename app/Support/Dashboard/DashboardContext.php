<?php

namespace App\Support\Dashboard;

/**
 * The data contract every dashboard widget factory resolves against: scalar
 * metrics for the expression engine plus named datasets (regions, install
 * trend, SLA queue...). Built from ProductDashboardService::summary(), so
 * widgets never touch the database themselves — region scope and period are
 * enforced upstream before a widget ever sees the data.
 */
final class DashboardContext
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, array<int, array<string, mixed>>>  $datasets
     */
    public function __construct(
        public readonly string $region,
        public readonly string $period,
        private readonly array $metrics,
        private readonly array $datasets,
    ) {}

    /**
     * @param  array<string, mixed>  $summary  ProductDashboardService::summary() output
     */
    public static function fromSummary(string $region, string $period, array $summary): self
    {
        $datasets = [];

        foreach ([
            'regions' => 'regions',
            'install_trend' => 'installTrend',
            'machine_types' => 'machineTypes',
            'top_accounts' => 'topAccounts',
            'fleet' => 'fleetDonut',
            'brands' => 'brandDonut',
            'kpis' => 'kpis',
            'sla' => 'sla',
            'attention' => 'attentionSignals',
        ] as $key => $summaryKey) {
            $datasets[$key] = is_array($summary[$summaryKey] ?? null) ? $summary[$summaryKey] : [];
        }

        return new self($region, $period, is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [], $datasets);
    }

    public function metric(string $key, mixed $default = null): mixed
    {
        return $this->metrics[$key] ?? $default;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dataset(string $key): array
    {
        return $this->datasets[$key] ?? [];
    }

    /**
     * The variable scope for the expression engine: every metric plus the
     * viewing scope itself.
     *
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        return [...$this->metrics, 'region' => $this->region, 'period' => $this->period];
    }
}
