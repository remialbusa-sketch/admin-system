<?php

namespace App\Support\Dashboard;

use Illuminate\Support\Str;

/**
 * One-click presets for the Add-widget dialog: fully-configured widgets
 * that reproduce the retired hardcoded page sections (regional tables,
 * donuts, trends) without rebuilding them by hand.
 *
 * Presets are scoped to a page (home|tsa|tsp) because their dataset/metric
 * props only resolve inside that page's vocabulary — a preset never shows
 * on a page whose context cannot explain it. Personal dashboards offer
 * bare widget types only.
 */
final class WidgetPresets
{
    public const PAGES = ['home', 'tsa', 'tsp'];

    /** Grid key => preset page for the customizable page grids. */
    public const PAGE_FOR_GRID = [
        'tsa' => 'tsa',
        'tsp' => 'tsp',
    ];

    /**
     * @return array<string, array{title: string, description: string, page: string, type: string, w: int, h: int, props: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            'regional-breakdown' => [
                'title' => 'Regional breakdown',
                'description' => 'Install base by region, with totals.',
                'page' => 'home',
                'type' => 'table',
                'w' => 6,
                'h' => 3,
                'props' => [
                    'label' => 'Regional breakdown',
                    'context' => 'Install base by region',
                    'dataset' => 'regions',
                    'label_key' => 'region',
                    'max_rows' => 8,
                    'totals' => true,
                    'tone' => 'primary',
                ],
            ],
            'fleet-mix' => [
                'title' => 'Fleet mix',
                'description' => 'Device-state composition donut.',
                'page' => 'home',
                'type' => 'donut_chart',
                'w' => 6,
                'h' => 4,
                'props' => [
                    'label' => 'Fleet mix',
                    'context' => 'Device state',
                    'dataset' => 'fleet',
                    'label_key' => 'label',
                    'value_key' => 'value',
                    'tone' => 'primary',
                ],
            ],
            'installations-trend' => [
                'title' => 'Installations trend',
                'description' => 'New installs over time, with momentum.',
                'page' => 'home',
                'type' => 'line_chart',
                'w' => 6,
                'h' => 3,
                'props' => [
                    'label' => 'Installations trend',
                    'context' => 'New installs per month',
                    'dataset' => 'install_trend',
                    'value_key' => 'count',
                    'area' => true,
                    'delta_metric' => 'install_delta',
                    'tone' => 'primary',
                ],
            ],
            'brand-mix' => [
                'title' => 'Brand mix',
                'description' => 'Leading-brands composition donut.',
                'page' => 'home',
                'type' => 'donut_chart',
                'w' => 6,
                'h' => 4,
                'props' => [
                    'label' => 'Brand mix',
                    'context' => 'Leading brands',
                    'dataset' => 'brands',
                    'label_key' => 'label',
                    'value_key' => 'value',
                    'tone' => 'primary',
                ],
            ],
            'equipment-types' => [
                'title' => 'Equipment types',
                'description' => 'Installed base by machine type.',
                'page' => 'home',
                'type' => 'bar_chart',
                'w' => 6,
                'h' => 3,
                'props' => [
                    'label' => 'Equipment types',
                    'context' => 'Base by machine type',
                    'dataset' => 'machine_types',
                    'label_key' => 'label',
                    'value_key' => 'total',
                    'orientation' => 'horizontal',
                    'tone' => 'primary',
                ],
            ],
            'largest-accounts' => [
                'title' => 'Largest accounts',
                'description' => 'Top customers by installed base.',
                'page' => 'home',
                'type' => 'table',
                'w' => 6,
                'h' => 3,
                'props' => [
                    'label' => 'Largest accounts',
                    'context' => 'Top customers by installed base',
                    'dataset' => 'top_accounts',
                    'label_key' => 'customer',
                    'max_rows' => 8,
                    'totals' => true,
                    'tone' => 'primary',
                ],
            ],
            'warranty-expiring' => [
                'title' => 'Warranties expiring',
                'description' => 'Warranties ending within 90 days.',
                'page' => 'home',
                'type' => 'supporting_kpi',
                'w' => 4,
                'h' => 1,
                'props' => [
                    'label' => 'Warranties expiring (90 days)',
                    'metric' => 'warranty_expiring_90d',
                    'formula' => '',
                    'suffix' => '',
                    'decimals' => 0,
                    'context' => '',
                    'context_metric' => 'warranty_expired',
                    'context_prefix' => '',
                    'context_suffix' => ' already past end date',
                    'context_decimals' => 0,
                    'href' => '',
                    'icon' => '',
                    'tone' => 'warning',
                ],
            ],
            'missing-pms' => [
                'title' => 'Missing PMS frequency',
                'description' => 'Records with no PMS frequency set.',
                'page' => 'home',
                'type' => 'supporting_kpi',
                'w' => 4,
                'h' => 1,
                'props' => [
                    'label' => 'Missing PMS frequency',
                    'metric' => 'missing_pms',
                    'formula' => '',
                    'suffix' => '',
                    'decimals' => 0,
                    'context' => '',
                    'context_metric' => 'missing_pms_ratio',
                    'context_prefix' => '',
                    'context_suffix' => '% of installed',
                    'context_decimals' => 1,
                    'href' => '',
                    'icon' => '',
                    'tone' => 'error',
                ],
            ],
            'expired-warranties' => [
                'title' => 'Expired warranties',
                'description' => 'Warranties already past end date.',
                'page' => 'home',
                'type' => 'supporting_kpi',
                'w' => 4,
                'h' => 1,
                'props' => [
                    'label' => 'Warranties past end date',
                    'metric' => 'warranty_expired',
                    'formula' => '',
                    'suffix' => '',
                    'decimals' => 0,
                    'context' => 'confirm renewals or update status',
                    'context_metric' => '',
                    'context_prefix' => '',
                    'context_suffix' => '',
                    'context_decimals' => 0,
                    'href' => '',
                    'icon' => '',
                    'tone' => 'error',
                ],
            ],
            'report-status' => [
                'title' => 'Report status mix',
                'description' => 'Technical reports by status.',
                'page' => 'tsa',
                'type' => 'donut_chart',
                'w' => 6,
                'h' => 4,
                'props' => [
                    'label' => 'Report status mix',
                    'context' => 'Status mix',
                    'dataset' => 'status',
                    'label_key' => 'label',
                    'value_key' => 'value',
                    'tone' => 'primary',
                ],
            ],
            'completions-trend' => [
                'title' => 'Completions trend',
                'description' => 'Completed reports over the window.',
                'page' => 'tsa',
                'type' => 'line_chart',
                'w' => 6,
                'h' => 3,
                'props' => [
                    'label' => 'Completions trend',
                    'context' => 'Completed reports',
                    'dataset' => 'trend',
                    'value_key' => 'count',
                    'area' => true,
                    'tone' => 'primary',
                ],
            ],
            'tsp-workload' => [
                'title' => 'Workload by TSP',
                'description' => 'Top TSPs by report count.',
                'page' => 'tsa',
                'type' => 'bar_chart',
                'w' => 6,
                'h' => 3,
                'props' => [
                    'label' => 'Workload by TSP',
                    'context' => 'Top TSPs by reports',
                    'dataset' => 'by_tsp',
                    'label_key' => 'label',
                    'value_key' => 'total',
                    'orientation' => 'horizontal',
                    'tone' => 'primary',
                ],
            ],
            'serviced-brands' => [
                'title' => 'Most serviced brands',
                'description' => 'Brand mix across reports.',
                'page' => 'tsa',
                'type' => 'donut_chart',
                'w' => 6,
                'h' => 4,
                'props' => [
                    'label' => 'Most serviced brands',
                    'context' => 'Brand mix',
                    'dataset' => 'brands',
                    'label_key' => 'label',
                    'value_key' => 'value',
                    'tone' => 'primary',
                ],
            ],
            'weekly-completions' => [
                'title' => 'Weekly completions',
                'description' => 'Completed reports per week.',
                'page' => 'tsp',
                'type' => 'line_chart',
                'w' => 6,
                'h' => 3,
                'props' => [
                    'label' => 'Weekly completions',
                    'context' => 'Completed reports per week',
                    'dataset' => 'trend',
                    'value_key' => 'resolved',
                    'area' => true,
                    'tone' => 'primary',
                ],
            ],
            'tsp-table' => [
                'title' => 'Per-TSP table',
                'description' => 'Reports, completion and repair by TSP.',
                'page' => 'tsp',
                'type' => 'table',
                'w' => 12,
                'h' => 4,
                'props' => [
                    'label' => 'Per-TSP table',
                    'context' => 'Reports, completion and repair by TSP',
                    'dataset' => 'top_tsp',
                    'label_key' => 'tsp_name',
                    'max_rows' => 25,
                    'totals' => false,
                    'tone' => 'primary',
                ],
            ],
            'regional-table' => [
                'title' => 'Regional table',
                'description' => 'Active TSPs and open records by region.',
                'page' => 'tsp',
                'type' => 'table',
                'w' => 6,
                'h' => 3,
                'props' => [
                    'label' => 'Regional table',
                    'context' => 'Active TSPs and open records by region',
                    'dataset' => 'regional',
                    'label_key' => 'region',
                    'max_rows' => 8,
                    'totals' => true,
                    'tone' => 'primary',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{title: string, description: string, page: string, type: string, w: int, h: int, props: array<string, mixed>}>
     */
    public static function forPage(string $page): array
    {
        return array_filter(
            self::all(),
            fn (array $preset): bool => $preset['page'] === $page,
        );
    }

    /** @return array{title: string, description: string, page: string, type: string, w: int, h: int, props: array<string, mixed>}|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Build a grid-ready widget instance from a preset (fresh id every
     * call, like addWidget).
     *
     * @return array{id: string, type: string, w: int, h: int, props: array<string, mixed>}|null
     */
    public static function make(string $key): ?array
    {
        $preset = self::find($key);

        if ($preset === null) {
            return null;
        }

        return [
            'id' => $key.'-'.strtolower(Str::random(6)),
            'type' => $preset['type'],
            'w' => (int) min(12, max(1, $preset['w'])),
            'h' => (int) min(6, max(1, $preset['h'])),
            'props' => $preset['props'],
        ];
    }
}
