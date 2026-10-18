<?php

namespace App\Support\Dashboard\Widgets;

use App\Services\DashboardColumnService;
use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;

final class ColumnChartWidget implements DashboardWidget
{
    public function __construct(private readonly DashboardColumnService $columns) {}

    public function type(): string
    {
        return 'column_chart';
    }

    public function definition(): array
    {
        return [
            'title' => 'Column chart builder',
            'description' => 'Build line, column, or bar charts from any connected table’s columns.',
            'defaultSize' => ['w' => 8, 'h' => 4],
            'defaultProps' => ['label' => 'New chart', 'column' => '', 'series_column' => '', 'value_column' => '',
                'aggregation' => 'count', 'grouping' => 'value', 'chart_type' => 'bar', 'orientation' => 'vertical',
                'show_values' => true, 'sort' => 'category', 'limit' => 30],
            'settings' => [
                ['key' => 'label', 'label' => 'Title', 'type' => 'text', 'required' => true],
                ['key' => 'column', 'label' => 'Category column', 'type' => 'column', 'required' => true],
                ['key' => 'grouping', 'label' => 'Category grouping', 'type' => 'select', 'options' => ['value', 'year', 'month', 'year_month', 'day'], 'help' => 'Use year_month for a chronological monthly trend. Month combines the same month across years.'],
                ['key' => 'series_column', 'label' => 'Split into series (optional)', 'type' => 'column', 'help' => 'Choose another column from the same table, e.g. Service Type for a chart grouped by Model.'],
                ['key' => 'aggregation', 'label' => 'Calculation', 'type' => 'select', 'options' => ['count', 'sum', 'avg', 'min', 'max'], 'help' => 'Count counts records. Other calculations use the numeric column.'],
                ['key' => 'value_column', 'label' => 'Numeric column (optional for count)', 'type' => 'numeric_column'],
                ['key' => 'chart_type', 'label' => 'Chart type', 'type' => 'select', 'options' => ['bar', 'line']],
                ['key' => 'orientation', 'label' => 'Bar orientation', 'type' => 'select', 'options' => ['vertical', 'horizontal']],
                ['key' => 'sort', 'label' => 'Category order', 'type' => 'select', 'options' => ['category', 'value_desc']],
                ['key' => 'limit', 'label' => 'Maximum categories (1–100)', 'type' => 'number'],
                ['key' => 'show_values', 'label' => 'Data labels', 'type' => 'boolean'],
            ],
        ];
    }

    public function resolve(array $props, DashboardContext $ctx): array
    {
        $props = array_merge($this->definition()['defaultProps'], $props);
        $chart = empty($props['column']) ? ['points' => [], 'series' => [], 'truncated' => false, 'seriesTruncated' => false]
            : $this->columns->chart($props, $ctx->sourceTables(), $ctx->filters);

        return ['view' => 'components.dashboard.widgets.column-chart', 'data' => $chart + [
            'label' => $props['label'], 'chartType' => $props['chart_type'] === 'line' ? 'line' : 'bar',
            'horizontal' => $props['orientation'] === 'horizontal', 'showValues' => (bool) $props['show_values'],
            'configured' => ! empty($props['column']),
            'contentHeight' => max(160, (int) ($props['_widget_height'] ?? 4) * 112 - 68),
        ]];
    }
}
