<?php

namespace App\Support\Dashboard;

use App\Services\DashboardColumnService;

/** The reference workbook's Dashboard sheet, connected to app report columns. */
class TechnicalServiceReference
{
    public const SOURCES = ['tr' => 'technical-reports'];

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

        return ['version' => 1, 'widgets' => [
            $slicer('tsa-service', 'Service Type', $service, 'value', 12, 2, 8),
            $slicer('tsa-year', 'Year', $date, 'year', 3, 2, 3),
            $slicer('tsa-month', 'Month', $date, 'month', 4, 2, 3),
            $slicer('tsa-model', 'Model', $model, 'value', 5, 2, 2),
            $chart('tsa-calls-month', 'Total Calls/Month', $date, ['chart_type' => 'line', 'grouping' => 'year_month'], 12, 5),
            $chart('tsa-service-model', 'Service Type by Model', $service === '' ? '' : $model, ['series_column' => $service, 'orientation' => 'vertical', 'show_values' => false], 12, 5),
            $chart('tsa-service-total', 'Service Type in Total', $service, ['orientation' => 'horizontal', 'sort' => 'value_desc'], 12, 5),
        ]];
    }
}
