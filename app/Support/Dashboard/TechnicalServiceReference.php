<?php

namespace App\Support\Dashboard;

use App\Services\DashboardColumnService;
use Illuminate\Support\Collection;

/** The reference workbook's Dashboard sheet, connected to app report columns. */
class TechnicalServiceReference
{
    public const SOURCES = ['tr' => 'technical-reports'];

    /** The widget ids this reference set owns, in canonical shipping order. */
    public const IDS = ['tsa-service', 'tsa-year', 'tsa-month', 'tsa-model',
        'tsa-calls-month', 'tsa-service-model', 'tsa-service-total'];

    /**
     * Chart-fields roles: these specific props are owned by the page's
     * Chart-fields panel (Call date / Model / Service Type) and are re-pointed
     * wherever those selects change — titles, spans and every other prop stay
     * user-configured.
     */
    private const ROLES = [
        'tsa-service' => ['column' => 'service'],
        'tsa-year' => ['column' => 'date'],
        'tsa-month' => ['column' => 'date'],
        'tsa-model' => ['column' => 'model'],
        'tsa-calls-month' => ['column' => 'date'],
        'tsa-service-model' => ['column' => 'model', 'series_column' => 'service'],
        'tsa-service-total' => ['column' => 'service'],
    ];

    /** The shipped reference widgets only: slicers first, then the three charts. */
    public static function layout(array $overrides = []): array
    {
        $columns = app(DashboardColumnService::class)->columns('technical-reports');
        $service = '';
        foreach (['service type', 'type of request'] as $label) {
            foreach ($columns as $key => $column) {
                if (mb_strtolower(trim(str_replace(' (custom)', '', $column['label']))) === $label) {
                    $service = 'tr.'.$key;
                    break 2;
                }
            }
        }
        $date = 'tr.service_started_at';
        $model = 'tr.machine_type';
        foreach (['date', 'model', 'service'] as $field) {
            $key = $overrides[$field] ?? null;
            if (is_string($key) && isset($columns[$key]) && ! isset($columns[$key]['merged_into'])
                && ($field !== 'date' || $columns[$key]['kind'] === 'date')) {
                ${$field} = 'tr.'.$key;
            }
        }
        $slicer = fn ($id, $label, $column, $mode, $w, $h, $buttons) => [
            'id' => $id, 'type' => 'slicer', 'w' => $w, 'h' => $h,
            'props' => ['label' => $label, 'column' => $column, 'mode' => $mode, 'columns' => $buttons, 'multiple' => true],
        ];
        $chart = fn ($id, $label, $column, $props, $w, $h) => [
            'id' => $id, 'type' => 'column_chart', 'w' => $w, 'h' => $h,
            'props' => $props + ['label' => $label, 'column' => $column, 'aggregation' => 'count', 'limit' => DashboardColumnService::MAX_CATEGORIES],
        ];

        $widgets = [
            $slicer('tsa-year', 'Year', $date, 'year', 3, 2, 3),
            $slicer('tsa-month', 'Month', $date, 'month', 4, 2, 3),
            $slicer('tsa-model', 'Model', $model, 'value', 5, 2, 2),
            $chart('tsa-calls-month', 'Total Calls/Month', $date, ['chart_type' => 'line', 'grouping' => 'year_month'], 12, 5),
        ];

        // No service column on this table: omit the service widgets entirely —
        // the workbook section has nothing to chart and a blank column degrades
        // to a dead card (never an error surface).
        if ($service !== '') {
            array_unshift($widgets, $slicer('tsa-service', 'Service Type', $service, 'value', 12, 2, 8));
            $widgets[] = $chart('tsa-service-model', 'Service Type by Model', $model, ['series_column' => $service, 'orientation' => 'vertical', 'show_values' => false], 12, 5);
            $widgets[] = $chart('tsa-service-total', 'Service Type in Total', $service, ['orientation' => 'horizontal', 'sort' => 'value_desc'], 12, 5);
        }

        return ['version' => 1, 'widgets' => $widgets];
    }

    /**
     * Merge the reference set into a TSA layout:
     *
     * - reference-id widgets found in the base keep their title, span and any
     *   user-configured prop, but have their Chart-fields role columns
     *   re-pointed to the current mapping;
     * - reference widgets the current columns can no longer configure (e.g.
     *   the Service Type column was deleted) are dropped;
     * - the missing reference widgets are appended at the end — but only for
     *   layouts that predate the merge. An explicitly saved layout
     *   (`reference_marked`, written on every Done) is the admin's final word:
     *   their deletions persist, nothing resurrects.
     *
     * @param  array<string, mixed>  $base
     * @return array{version: int, widgets: array<int, array<string, mixed>>}
     */
    public static function merge(array $base, array $overrides = []): array
    {
        $canonical = new Collection(self::layout($overrides)['widgets']);
        $canonical = $canonical->keyBy('id');
        $widgets = is_array($base['widgets'] ?? null) ? $base['widgets'] : [];
        $out = [];

        foreach ($widgets as $widget) {
            if (! is_array($widget)) {
                continue;
            }

            if ($canonical->has($widget['id'])) {
                $fresh = $canonical->get($widget['id']);
                $fresh['w'] = $widget['w'] ?? $fresh['w'];
                $fresh['h'] = $widget['h'] ?? $fresh['h'];
                $fresh['props'] = self::retargeted($widget['id'], $widget['props'] ?? [], $fresh['props']);
                $out[] = $fresh;
                $canonical->forget($widget['id']);

                continue;
            }

            // A reference-id widget the shipped default no longer defines is a
            // dead card (its column vanished) — it never renders.
            if (in_array($widget['id'], self::IDS, true) && in_array($widget['type'] ?? '', ['column_chart', 'slicer'], true)) {
                continue;
            }

            $out[] = $widget;
        }

        if (($base['reference_marked'] ?? false) !== true && $widgets !== []) {
            foreach ($canonical as $widget) {
                $out[] = $widget;
            }
        }

        return ['version' => 1, 'widgets' => array_values($out)];
    }

    /**
     * The user's saved props win — except the Chart-fields role columns and a
     * blank title, which follow the shipped default.
     *
     * @param  array<string, mixed>  $savedProps
     * @param  array<string, mixed>  $canonicalProps
     * @return array<string, mixed>
     */
    private static function retargeted(string $id, array $savedProps, array $canonicalProps): array
    {
        $props = $savedProps === [] ? $canonicalProps : $savedProps;

        foreach (self::ROLES[$id] ?? [] as $key => $role) {
            if (($canonicalProps[$key] ?? '') !== '') {
                $props[$key] = $canonicalProps[$key];
            }
        }

        if (! isset($props['label']) || trim((string) $props['label']) === '') {
            $props['label'] = $canonicalProps['label'] ?? '';
        }

        return $props;
    }
}
