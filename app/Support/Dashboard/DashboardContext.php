<?php

namespace App\Support\Dashboard;

/**
 * The data contract every dashboard widget factory resolves against: scalar
 * metrics for the expression engine plus named datasets (regions, install
 * trend, SLA queue...). Built from ProductDashboardService::summary(), so
 * widgets never touch the database themselves — region scope and period are
 * enforced upstream before a widget ever sees the data.
 *
 * Multi-table dashboards add connected sources (alias => {metrics, datasets});
 * dataset keys are then namespaced "alias.name", while bare names keep
 * resolving against the default (Product Database) datasets for backward
 * compatibility.
 */
final class DashboardContext
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, array<int, array<string, mixed>>>  $datasets
     * @param  array<string, array{metrics: array<string, mixed>, datasets: array<string, array<int, array<string, mixed>>>}>  $sources
     */
    public function __construct(
        public readonly string $region,
        public readonly string $period,
        private readonly array $metrics,
        private readonly array $datasets,
        private readonly array $sources = [],
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

    /**
     * Attach the dashboard's connected sources (alias => aggregation summary).
     *
     * @param  array<string, array{metrics: array<string, mixed>, datasets: array<string, array<int, array<string, mixed>>>}>  $sources
     */
    public function withSources(array $sources): self
    {
        return new self($this->region, $this->period, $this->metrics, $this->datasets, $sources);
    }

    public function metric(string $key, mixed $default = null): mixed
    {
        if (str_contains($key, '.')) {
            [$alias, $name] = explode('.', $key, 2);

            return $this->sources[$alias]['metrics'][$name] ?? $default;
        }

        return $this->metrics[$key] ?? $default;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dataset(string $key): array
    {
        if (str_contains($key, '.')) {
            [$alias, $name] = explode('.', $key, 2);

            return $this->sources[$alias]['datasets'][$name] ?? [];
        }

        return $this->datasets[$key] ?? [];
    }

    /**
     * Every dataset key widgets may reference: the default (bare) keys plus
     * "alias.name" for each connected source.
     *
     * @return array<int, string>
     */
    public function datasetKeys(): array
    {
        $keys = array_keys($this->datasets);

        foreach ($this->sources as $alias => $source) {
            foreach (array_keys($source['datasets'] ?? []) as $name) {
                $keys[] = $alias.'.'.$name;
            }
        }

        return $keys;
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
