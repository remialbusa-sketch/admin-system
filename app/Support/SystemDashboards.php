<?php

namespace App\Support;

/**
 * The three curated analytics experiences seeded as system dashboards:
 * Home (Product Database overview), Technical Service Analysis, and
 * TSP Analytics. They appear in the dashboards index for everyone; opening
 * one gives the user an editable personal copy (Dashboard::mount) so the
 * shared template can never be broken by one person's edits.
 *
 * Layouts are built from the aggregation datasets exposed by
 * TableAggregationService, so every widget is live from the imported data.
 */
class SystemDashboards
{
    /**
     * @return array<int, array{name: string, description: string, sources: array<int, array{table_key: string, alias: string}>, layout: array<string, mixed>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'Home',
                'description' => 'Product Database overview — installed base, warranty and contract posture, fleet state.',
                'sources' => [
                    ['table_key' => 'installed-products', 'alias' => 'pdb'],
                ],
                'layout' => config('dashboard.default_layout'),
            ],
            [
                'name' => 'Technical Service Analysis',
                'description' => 'Technical Reports overview — status mix, completion trend, TSP workload and brands.',
                'sources' => [
                    ['table_key' => 'technical-reports', 'alias' => 'tr'],
                ],
                'layout' => [
                    'version' => 1,
                    'widgets' => [
                        self::widget('kpi-reports', 'kpi_card', 3, 2, [
                            'label' => 'Technical reports', 'metric' => 'tr.rows', 'tone' => 'primary',
                            'context' => 'all imported reports', 'href' => '/technical-reports',
                        ]),
                        self::widget('kpi-completed', 'kpi_card', 3, 2, [
                            'label' => 'Completed', 'metric' => 'tr.completed', 'tone' => 'success',
                            'context' => 'service_status = completed', 'href' => '/technical-reports',
                        ]),
                        self::widget('kpi-repair', 'kpi_card', 3, 2, [
                            'label' => 'Avg repair time', 'metric' => 'tr.avg_repair_hours', 'suffix' => 'h', 'decimals' => 1,
                            'tone' => 'info', 'context' => 'mean repair_time_hours', 'href' => '/technical-reports',
                        ]),
                        self::widget('kpi-unassigned', 'kpi_card', 3, 2, [
                            'label' => 'Unassigned', 'metric' => 'tr.unassigned', 'tone' => 'warning',
                            'context' => 'no TSP recorded', 'href' => '/technical-reports?assigned=0',
                        ]),
                        self::widget('donut-status', 'donut_chart', 4, 3, [
                            'label' => 'Status mix', 'dataset' => 'tr.by_status', 'label_key' => 'label', 'value_key' => 'value',
                        ]),
                        self::widget('trend-completion', 'line_chart', 8, 3, [
                            'label' => 'Completion trend', 'dataset' => 'tr.by_month', 'value_key' => 'count',
                        ]),
                        self::widget('bars-tsp', 'bar_chart', 6, 3, [
                            'label' => 'TSP workload', 'dataset' => 'tr.by_tsp', 'label_key' => 'label', 'value_key' => 'value',
                        ]),
                        self::widget('donut-brands', 'donut_chart', 6, 3, [
                            'label' => 'Brands serviced', 'dataset' => 'tr.by_brand', 'label_key' => 'label', 'value_key' => 'value',
                        ]),
                    ],
                ],
            ],
            [
                'name' => 'TSP Analytics',
                'description' => 'Service request flow by region and branch, TSP workload and the personnel register.',
                'sources' => [
                    ['table_key' => 'service-requests', 'alias' => 'sr'],
                    ['table_key' => 'personnel', 'alias' => 'tp'],
                    ['table_key' => 'technical-reports', 'alias' => 'tr'],
                ],
                'layout' => [
                    'version' => 1,
                    'widgets' => [
                        self::widget('kpi-requests', 'kpi_card', 3, 2, [
                            'label' => 'Service requests', 'metric' => 'sr.rows', 'tone' => 'primary',
                            'context' => 'all imported requests', 'href' => '/service-requests',
                        ]),
                        self::widget('kpi-open', 'kpi_card', 3, 2, [
                            'label' => 'Open', 'metric' => 'sr.open', 'tone' => 'warning',
                            'context' => 'not completed yet', 'href' => '/service-requests',
                        ]),
                        self::widget('kpi-rate', 'kpi_card', 3, 2, [
                            'label' => 'Completion rate', 'metric' => 'sr.completion_rate', 'suffix' => '%', 'decimals' => 1,
                            'tone' => 'success', 'context' => 'completed / total', 'href' => '/service-requests',
                        ]),
                        self::widget('kpi-personnel', 'kpi_card', 3, 2, [
                            'label' => 'Technical personnel', 'metric' => 'tp.rows', 'tone' => 'info',
                            'context' => 'on the register', 'href' => '/personnel',
                        ]),
                        self::widget('bars-region', 'bar_chart', 6, 3, [
                            'label' => 'Requests by region', 'dataset' => 'sr.by_region', 'label_key' => 'label', 'value_key' => 'value',
                        ]),
                        self::widget('donut-group', 'donut_chart', 6, 3, [
                            'label' => 'Requests by group', 'dataset' => 'sr.by_group', 'label_key' => 'label', 'value_key' => 'value',
                        ]),
                        self::widget('bars-branch', 'bar_chart', 6, 3, [
                            'label' => 'Requests by branch', 'dataset' => 'sr.by_branch', 'label_key' => 'label', 'value_key' => 'value',
                        ]),
                        self::widget('bars-tsp', 'bar_chart', 6, 3, [
                            'label' => 'TSP workload', 'dataset' => 'tr.by_tsp', 'label_key' => 'label', 'value_key' => 'value',
                        ]),
                        self::widget('bars-personnel-region', 'bar_chart', 6, 3, [
                            'label' => 'Personnel by region', 'dataset' => 'tp.by_region', 'label_key' => 'label', 'value_key' => 'value',
                        ]),
                        self::widget('table-positions', 'table', 6, 3, [
                            'label' => 'Personnel by position', 'dataset' => 'tp.by_position', 'label_key' => 'label',
                        ]),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function widget(string $id, string $type, int $w, int $h, array $props): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'w' => $w,
            'h' => $h,
            'props' => $props,
        ];
    }
}
