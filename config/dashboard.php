<?php

/*
|--------------------------------------------------------------------------
| Dashboard grid layout engine
|--------------------------------------------------------------------------
|
| Single source of truth for the widget registry, the shipped default
| layout, and the expression-driven widget defaults. The grid is 12
| columns wide with span-based placement (w/h); responsive spans below
| md collapse every widget to full width.
|
| Widget values, RAG thresholds, goals, heatmap columns and the risk
| formula are all EXPRESSIONS evaluated by the runtime expression engine
| (App\Support\Dashboard\ExpressionEngine) against the DashboardContext
| variable scope: every metric exposed by ProductDashboardService::summary()
| plus the viewing scope (region, period). Safe by construction: no eval,
| allow-listed functions, closed grammar.
|
*/

return [

    // Widget generator (the "Add widget" picker). The factories and
    // add-widget flow are always wired; this flag only gates the UI and
    // the addWidget action. Currently enabled: the "Add widget" button
    // shows in the Customize grid toolbar. Set to false to hide it again.
    'allow_add_widgets' => true,

    // Widget type → factory class. The layout normalizer accepts exactly
    // these types; anything else in a (user-tampered) layout is dropped.
    // Legacy type keys (kpi, goal, trend, pivot, sla) are migrated forward
    // by GridLayoutNormalizer so saved layouts survive the rename.
    'widgets' => [
        'kpi_card' => App\Support\Dashboard\Widgets\KpiCardWidget::class,
        'stat' => App\Support\Dashboard\Widgets\StatWidget::class,
        'progress' => App\Support\Dashboard\Widgets\ProgressWidget::class,
        'gauge' => App\Support\Dashboard\Widgets\GaugeWidget::class,
        'line_chart' => App\Support\Dashboard\Widgets\LineChartWidget::class,
        'bar_chart' => App\Support\Dashboard\Widgets\BarChartWidget::class,
        'donut_chart' => App\Support\Dashboard\Widgets\DonutChartWidget::class,
        'table' => App\Support\Dashboard\Widgets\TableWidget::class,
        'heatmap' => App\Support\Dashboard\Widgets\HeatmapWidget::class,
        'sla_countdown' => App\Support\Dashboard\Widgets\SlaCountdownWidget::class,
    ],

    // The shipped grid: one of every standard widget, arranged top-left to
    // bottom-right. Every value on it is either a metric or a visually
    // built formula evaluated by the expression engine.
    'default_layout' => [
        'version' => 1,
        'widgets' => [
            [
                'id' => 'kpi-activation', 'type' => 'kpi_card', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Fleet activation',
                    'formula' => 'active / installed * 100',
                    'suffix' => '%',
                    'decimals' => 1,
                    'context' => 'active share of the installed base',
                    'href' => '/installed-products?status=Active',
                    'green_above' => 90,
                    'amber_above' => 75,
                    'sparkline' => true,
                    'trend_metric' => 'install_delta',
                    'tone' => 'primary',
                ],
            ],
            [
                'id' => 'stat-installed', 'type' => 'stat', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Installed products',
                    'metric' => 'installed',
                    'delta_metric' => 'install_delta',
                    'context' => 'units on file',
                    'tone' => 'primary',
                ],
            ],
            [
                'id' => 'sla-warranty', 'type' => 'sla_countdown', 'w' => 4, 'h' => 3,
                'props' => [
                    'label' => 'Warranty expiry countdown',
                    'context' => 'Soonest warranty end dates in scope',
                    'metric_label' => 'expiring',
                    'limit' => 4,
                    'tone' => 'warning',
                ],
            ],
            [
                'id' => 'trend-installs', 'type' => 'line_chart', 'w' => 8, 'h' => 3,
                'props' => [
                    'label' => 'Installation momentum',
                    'context' => 'Units installed per month',
                    'dataset' => 'install_trend',
                    'value_key' => 'count',
                    'area' => true,
                    'delta_metric' => 'install_delta',
                    'tone' => 'primary',
                ],
            ],
            [
                'id' => 'gauge-active', 'type' => 'gauge', 'w' => 4, 'h' => 3,
                'props' => [
                    'label' => 'Active ratio',
                    'metric' => 'active_ratio',
                    'unit' => '%',
                    'max' => 100,
                    'green_above' => 90,
                    'amber_above' => 75,
                    'tone' => 'success',
                ],
            ],
            [
                'id' => 'donut-fleet', 'type' => 'donut_chart', 'w' => 4, 'h' => 3,
                'props' => [
                    'label' => 'Fleet state',
                    'context' => 'Composition of the installed base',
                    'dataset' => 'fleet',
                    'tone' => 'primary',
                ],
            ],
            [
                'id' => 'bars-machine-types', 'type' => 'bar_chart', 'w' => 8, 'h' => 3,
                'props' => [
                    'label' => 'Machine mix',
                    'context' => 'Top machine types by installations',
                    'dataset' => 'machine_types',
                    'orientation' => 'horizontal',
                    'tone' => 'info',
                ],
            ],
            [
                'id' => 'heatmap-regions', 'type' => 'heatmap', 'w' => 6, 'h' => 3,
                'props' => [
                    'label' => 'Regional heat',
                    'context' => 'Install vs active vs warranty posture',
                    'dataset' => 'regions',
                    'tone' => 'primary',
                ],
            ],
            [
                'id' => 'table-regions', 'type' => 'table', 'w' => 6, 'h' => 3,
                'props' => [
                    'label' => 'Regional position',
                    'context' => 'Install base by region',
                    'dataset' => 'regions',
                    'label_key' => 'region',
                    'max_rows' => 8,
                    'totals' => true,
                    'tone' => 'primary',
                ],
            ],
            [
                'id' => 'progress-warranty', 'type' => 'progress', 'w' => 12, 'h' => 2,
                'props' => [
                    'label' => 'Warranty coverage goal',
                    'context' => 'Share of the installed base under warranty vs the 60% target',
                    'current' => 'warranty_ratio',
                    'goal' => '60',
                    'unit' => '%',
                    'tone' => 'success',
                ],
            ],
        ],
    ],

    // Risk scoring: per-region weighted expression (0–100, higher = riskier).
    // Region-local variables: products, active, warranty, active_ratio,
    // warranty_ratio, plus the global install_delta.
    'risk' => [
        'formula' => 'round(100 - active_ratio * 0.45 - warranty_ratio * 0.35 - max(coalesce(install_delta, 0), 0) * 0.20, 0)',
        'bands' => ['high' => 60, 'watch' => 35],
    ],

    // Accent tones widgets can be styled with (settings modal "Accent" field).
    'tones' => ['primary', 'success', 'warning', 'error', 'info'],

    // Datasets widget factories may point at (settings modal "Dataset" field).
    'datasets' => [
        'regions' => 'Regional position',
        'install_trend' => 'Installations per month',
        'machine_types' => 'Machine types',
        'top_accounts' => 'Top accounts',
        'fleet' => 'Fleet state donut',
        'brands' => 'Brand donut',
        'kpis' => 'Headline KPIs',
        'sla' => 'SLA countdown queue',
        'attention' => 'Attention signals',
    ],

    // Human labels for the visual clause builder's metric dropdowns.
    'metric_labels' => [
        'installed' => 'Installed products',
        'active' => 'Active products',
        'pulled_out' => 'Pulled out',
        'warranty_covered' => 'Warranty covered',
        'contracts' => 'Service contracts',
        'missing_pms' => 'Missing PMS frequency',
        'annual_bu_charges' => 'Annual BU charges',
        'accounts' => 'Accounts on file',
        'warranty_expiring_90d' => 'Warranties expiring (90 days)',
        'warranty_expired' => 'Warranties expired',
        'active_ratio' => 'Active ratio',
        'warranty_ratio' => 'Warranty ratio',
        'missing_pms_ratio' => 'Missing PMS ratio',
        'install_delta' => 'Installation momentum change',
        'products' => 'Installed units (row)',
        'warranty' => 'Warranty units (row)',
        'region' => 'Region',
        'period' => 'Period',
    ],

    // Conditional heatmap columns. 'max' pins the intensity scale (use for
    // percent columns); otherwise intensity is relative to the column maximum
    // in scope. 'tone' selects the theme color the fill mixes toward.
    'heatmap_columns' => [
        ['label' => 'Installed', 'expr' => 'products'],
        ['label' => 'Active', 'expr' => 'active'],
        ['label' => 'Active %', 'expr' => 'pct(active, products)', 'max' => 100, 'tone' => 'success'],
        ['label' => 'Warranty %', 'expr' => 'pct(warranty, products)', 'max' => 100],
    ],

];
