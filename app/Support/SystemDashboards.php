<?php

namespace App\Support;

use App\Models\Dashboard;
use App\Services\TechnicalServiceAnalysisService;

/**
 * The three core (system) dashboards: Home, Technical Service Analysis, and
 * TSP Analytics. Each row (is_system = true) is the single shared layout
 * store behind its canonical page — everyone views the same widgets, and
 * only superadmin/admin accounts edit them (Dashboard::permissionFor,
 * User::canEditCoreDashboards). Opening a system row redirects to its page
 * (routeNameFor), so the rows are never rendered by the generic dashboard
 * component — their vocabularies are the pages' bare metric keys.
 *
 * The builders below are the shipped defaults: the seed migration writes
 * them into the rows, Reset restores them, and the pages fall back to them
 * when a row has no layout yet.
 */
class SystemDashboards
{
    /** @return array<int, array{name: string, description: string, sources: array<int, array{table_key: string, alias: string}>, layout: array<string, mixed>}> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'Home',
                'description' => 'Product Database overview — installed base, warranty and contract posture, fleet state.',
                // No connected source: Home's widgets speak the bare Product
                // Database vocabulary from config('dashboard.metric_labels'),
                // which a source would shrink to `pdb.*` only.
                'sources' => [],
                'layout' => self::homeLayout(),
            ],
            [
                'name' => 'Technical Service Analysis',
                'description' => 'Technical Reports overview — status mix, completion trend, TSP workload and brands.',
                'sources' => [
                    ['table_key' => 'technical-reports', 'alias' => 'tr'],
                ],
                'layout' => self::tsaLayout(),
            ],
            [
                'name' => 'TSP Analytics',
                'description' => 'Service request flow by region and branch, TSP workload and the personnel register.',
                'sources' => [
                    ['table_key' => 'service-requests', 'alias' => 'sr'],
                    ['table_key' => 'personnel', 'alias' => 'tp'],
                    ['table_key' => 'technical-reports', 'alias' => 'tr'],
                ],
                'layout' => self::tspLayout(),
            ],
        ];
    }

    /**
     * The canonical page route for a core dashboard name, or null when the
     * name is not one of the three core pages (custom system dashboards
     * render directly on dashboards/{id}).
     */
    public static function routeNameFor(?string $name): ?string
    {
        return match ($name) {
            'Home' => 'dashboard',
            'Technical Service Analysis' => 'technical-service-analysis',
            'TSP Analytics' => 'tsp-analytics',
            default => null,
        };
    }

    /**
     * The system row backing a core page (archived rows still back the page
     * — archiving only hides them from the index).
     */
    public static function coreRow(string $name): ?Dashboard
    {
        return Dashboard::query()
            ->withTrashed()
            ->where('is_system', true)
            ->where('name', $name)
            ->first();
    }

    /**
     * Persist a core row's shared layout (null = back to the shipped
     * default, which pages resolve through defaultLayout()). Creates the row
     * if the seed migration never ran.
     */
    public static function storeLayout(string $name, ?array $layout): void
    {
        $row = self::coreRow($name);

        if ($row === null) {
            $definition = collect(self::definitions())->firstWhere('name', $name);

            if ($definition === null) {
                return;
            }

            Dashboard::query()->create([
                'owner_id' => null,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'is_system' => true,
                'layout' => $layout,
            ]);

            return;
        }

        $row->update(['layout' => $layout]);
    }

    /** The shipped default layout for a core dashboard name, if it has one. */
    public static function defaultLayout(string $name): ?array
    {
        return collect(self::definitions())->firstWhere('name', $name)['layout'] ?? null;
    }

    /**
     * The shipped Home layout: the headline widgets first, then the full
     * shipped operations grid. Nothing is dropped — admins delete what they
     * don't want (Reset returns here). Live route() links keep drill-downs
     * working.
     *
     * @return array{version: int, widgets: array<int, array<string, mixed>>}
     */
    public static function homeLayout(): array
    {
        $widgets = self::homeHeadlineWidgets();

        foreach (config('dashboard.default_layout.widgets', []) as $widget) {
            $widgets[] = $widget;
        }

        return ['version' => 1, 'widgets' => $widgets];
    }

    /**
     * The shipped Home headlines as real widgets: same cards, same numbers.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function homeHeadlineWidgets(): array
    {
        return [
            [
                'id' => 'home-installed', 'type' => 'headline_kpi', 'w' => 4, 'h' => 3,
                'props' => [
                    'label' => 'Installed products', 'variant' => 'lead', 'metric' => 'installed',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => '', 'context_metric' => 'accounts', 'context_prefix' => '',
                    'context_suffix' => ' accounts on file', 'context_decimals' => 0,
                    'caption' => 'units installed', 'href' => route('installed-products'),
                ],
            ],
            [
                'id' => 'home-active', 'type' => 'headline_kpi', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Active products', 'variant' => 'card', 'metric' => 'active',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => '', 'context_metric' => 'active_ratio', 'context_prefix' => '',
                    'context_suffix' => '% of installed', 'context_decimals' => 1,
                    'caption' => '', 'href' => route('installed-products', ['status' => 'Active']),
                ],
            ],
            [
                'id' => 'home-warranty', 'type' => 'headline_kpi', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Warranty covered', 'variant' => 'card', 'metric' => 'warranty_covered',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => '', 'context_metric' => 'warranty_ratio', 'context_prefix' => '',
                    'context_suffix' => '% of installed', 'context_decimals' => 1,
                    'caption' => '', 'href' => route('installed-products', ['warranty' => 'covered']),
                ],
            ],
            [
                'id' => 'home-contracts', 'type' => 'headline_kpi', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Service contracts', 'variant' => 'card', 'metric' => 'contracts',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'active + renewal',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'caption' => '', 'href' => route('installed-products', ['contract' => '1']),
                ],
            ],
            [
                'id' => 'home-annual', 'type' => 'headline_kpi', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Annual BU charges', 'variant' => 'card', 'metric' => '',
                    'formula' => 'round(annual_bu_charges / 1000000, 1)', 'suffix' => 'M', 'decimals' => 1,
                    'context' => 'sum of annual charges on file',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'caption' => '', 'href' => route('installed-products'),
                ],
            ],
            [
                'id' => 'home-missing-pms', 'type' => 'supporting_kpi', 'w' => 4, 'h' => 1,
                'props' => [
                    'label' => 'Missing PMS frequency', 'metric' => 'missing_pms',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => '', 'context_metric' => 'missing_pms_ratio', 'context_prefix' => '',
                    'context_suffix' => '% of installed', 'context_decimals' => 1,
                    'href' => route('installed-products', ['pms' => 'missing']), 'icon' => '', 'tone' => 'error',
                ],
            ],
            [
                'id' => 'home-warranty-expiring', 'type' => 'supporting_kpi', 'w' => 4, 'h' => 1,
                'props' => [
                    'label' => 'Warranties expiring (90 days)', 'metric' => 'warranty_expiring_90d',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => '', 'context_metric' => 'warranty_expired', 'context_prefix' => '',
                    'context_suffix' => ' already past end date', 'context_decimals' => 0,
                    'href' => route('installed-products', ['warranty' => 'expiring_90d']), 'icon' => '', 'tone' => 'warning',
                ],
            ],
            [
                'id' => 'home-pulled-out', 'type' => 'supporting_kpi', 'w' => 4, 'h' => 1,
                'props' => [
                    'label' => 'Pulled out', 'metric' => 'pulled_out',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'removed from service',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => route('installed-products', ['status' => 'Pulledout']), 'icon' => '', 'tone' => 'primary',
                ],
            ],
        ];
    }

    /**
     * The shipped TSA cards as real widgets: same cards, same numbers,
     * fully customizable. The window card's drill-down follows the period
     * it was built for.
     *
     * @return array{version: int, widgets: array<int, array<string, mixed>>}
     */
    public static function tsaLayout(string $period = '30D'): array
    {
        $days = TechnicalServiceAnalysisService::PERIODS[$period] ?? 30;
        $trendFrom = now()->today()->subDays($days - 1)->toDateString();

        return ['version' => 1, 'widgets' => [
            [
                'id' => 'tsa-reports', 'type' => 'headline_kpi', 'w' => 4, 'h' => 3,
                'props' => [
                    'label' => 'Technical reports', 'variant' => 'lead', 'metric' => 'reports_total',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'imported service reports',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'caption' => 'reports on file', 'href' => route('technical-reports'),
                ],
            ],
            [
                'id' => 'tsa-completed', 'type' => 'headline_kpi', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Completed', 'variant' => 'card', 'metric' => 'completed',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'have a completion time',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'caption' => '', 'href' => route('technical-reports', ['completed' => 'any']),
                ],
            ],
            [
                'id' => 'tsa-assigned', 'type' => 'headline_kpi', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Assigned TSP', 'variant' => 'card', 'metric' => 'assigned_tsp',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => '', 'context_metric' => 'unassigned', 'context_prefix' => '',
                    'context_suffix' => ' unassigned', 'context_decimals' => 0,
                    'caption' => '', 'href' => route('technical-reports', ['assigned' => '1']),
                ],
            ],
            [
                'id' => 'tsa-repair', 'type' => 'headline_kpi', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Avg repair time', 'variant' => 'card', 'metric' => 'avg_repair_hours',
                    'formula' => '', 'suffix' => 'h', 'decimals' => 1,
                    'context' => 'mean per report',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'caption' => '', 'href' => route('technical-reports'),
                ],
            ],
            [
                'id' => 'tsa-response', 'type' => 'supporting_kpi', 'w' => 4, 'h' => 1,
                'props' => [
                    'label' => 'Avg response time', 'metric' => 'avg_response_hours',
                    'formula' => '', 'suffix' => 'h', 'decimals' => 1,
                    'context' => 'from report data',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => route('technical-reports'), 'icon' => '', 'tone' => 'primary',
                ],
            ],
            [
                'id' => 'tsa-window', 'type' => 'supporting_kpi', 'w' => 4, 'h' => 1,
                'props' => [
                    'label' => 'Completed · {period}', 'metric' => 'window_completed',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'in the selected window',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => route('technical-reports', ['completed_from' => $trendFrom, 'completed_to' => now()->toDateString()]),
                    'icon' => '', 'tone' => 'success',
                ],
            ],
            [
                'id' => 'tsa-unassigned', 'type' => 'supporting_kpi', 'w' => 4, 'h' => 1,
                'props' => [
                    'label' => 'Unassigned reports', 'metric' => 'unassigned',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'no TSP on record',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => route('technical-reports', ['assigned' => '0']), 'icon' => '', 'tone' => 'error',
                ],
            ],
        ]];
    }

    /**
     * The shipped TSP cards as real widgets: same cards, same numbers,
     * fully customizable.
     *
     * @return array{version: int, widgets: array<int, array<string, mixed>>}
     */
    public static function tspLayout(): array
    {
        return ['version' => 1, 'widgets' => [
            [
                'id' => 'tsp-filtered', 'type' => 'supporting_kpi', 'w' => 3, 'h' => 1,
                'props' => [
                    'label' => 'Reports (filtered)', 'metric' => 'filtered_reports',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'matching filters',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => '', 'icon' => 'o-document-text', 'tone' => 'primary',
                ],
            ],
            [
                'id' => 'tsp-distinct', 'type' => 'supporting_kpi', 'w' => 3, 'h' => 1,
                'props' => [
                    'label' => 'Distinct TSPs', 'metric' => 'distinct_tsps',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'in current scope',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => '', 'icon' => 'o-users', 'tone' => 'info',
                ],
            ],
            [
                'id' => 'tsp-completion', 'type' => 'supporting_kpi', 'w' => 3, 'h' => 1,
                'props' => [
                    'label' => 'Completion rate', 'metric' => 'completion_rate',
                    'formula' => '', 'suffix' => '%', 'decimals' => 1,
                    'context' => 'completed reports',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => '', 'icon' => 'o-shield-check', 'tone' => 'success',
                ],
            ],
            [
                'id' => 'tsp-repair', 'type' => 'supporting_kpi', 'w' => 3, 'h' => 1,
                'props' => [
                    'label' => 'Avg repair time', 'metric' => 'avg_repair_hours',
                    'formula' => '', 'suffix' => 'h', 'decimals' => 2,
                    'context' => 'per report',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => '', 'icon' => 'o-clock', 'tone' => 'warning',
                ],
            ],
        ]];
    }
}
