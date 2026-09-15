<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\SvgTrend;

/**
 * Line Chart: a time series from a dataset (installations per month) drawn
 * as an SVG line with an optional area fill, month labels and a momentum
 * chip. Points deep-link to the underlying records when the dataset
 * provides hrefs.
 */
final class LineChartWidget implements DashboardWidget
{
    public function __construct(private readonly ExpressionEngine $expressions) {}

    public function type(): string
    {
        return 'line_chart';
    }

    public function definition(): array
    {
        return [
            'title' => 'Line Chart',
            'description' => 'Trend over time with optional area fill and momentum chip.',
            'defaultSize' => ['w' => 8, 'h' => 3],
            'defaultProps' => [
                'label' => 'New line chart',
                'context' => '',
                'dataset' => 'install_trend',
                'value_key' => 'count',
                'area' => true,
                'delta_metric' => 'install_delta',
                'tone' => 'primary',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'context', 'label' => 'Context line', 'type' => 'text'],
                ['key' => 'dataset', 'label' => 'Dataset', 'type' => 'dataset', 'required' => true,
                    'options' => ['install_trend'], 'help' => 'Time-series datasets only.'],
                ['key' => 'value_key', 'label' => 'Value column', 'type' => 'text', 'help' => 'Row key holding the number (install_trend uses "count").'],
                ['key' => 'area', 'label' => 'Fill area under line', 'type' => 'boolean'],
                ['key' => 'delta_metric', 'label' => 'Momentum metric', 'type' => 'metric',
                    'help' => 'Optional chip: positive up, negative down.'],
                ['key' => 'tone', 'label' => 'Accent', 'type' => 'select', 'options' => config('dashboard.tones', ['primary'])],
            ],
        ];
    }

    public function resolve(array $props, DashboardContext $ctx): array
    {
        $rows = $ctx->dataset((string) ($props['dataset'] ?? 'install_trend'));
        $valueKey = (string) ($props['value_key'] ?? 'count');

        $labels = [];
        $values = [];
        $hrefs = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row[$valueKey]) || ! is_numeric($row[$valueKey])) {
                continue;
            }

            $labels[] = (string) ($row['short'] ?? $row['label'] ?? '');
            $values[] = (float) $row[$valueKey];
            $hrefs[] = isset($row['href']) ? (string) $row['href'] : null;
        }

        $spark = SvgTrend::build($values, 640, 140, 14);

        $delta = null;

        if (isset($props['delta_metric']) && trim((string) $props['delta_metric']) !== '') {
            $evaluated = $this->expressions->evaluate((string) $props['delta_metric'], $ctx->variables());

            if (is_numeric($evaluated)) {
                $delta = ['direction' => $evaluated >= 0 ? 'up' : 'down', 'value' => (float) $evaluated];
            }
        }

        return [
            'view' => 'components.dashboard.widgets.line-chart',
            'data' => [
                'label' => (string) ($props['label'] ?? 'Trend'),
                'context' => (string) ($props['context'] ?? ''),
                'labels' => $labels,
                'values' => $values,
                'hrefs' => $hrefs,
                'chart' => $spark,
                'area' => (bool) ($props['area'] ?? true),
                'delta' => $delta,
                'tone' => (string) ($props['tone'] ?? 'primary'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
