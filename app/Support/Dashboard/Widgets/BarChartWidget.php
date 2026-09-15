<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;

/**
 * Bar Chart: categories compared as horizontal bars (CSS) or vertical
 * columns (SVG) from any label/value dataset — regional position, machine
 * mix, top accounts.
 */
final class BarChartWidget implements DashboardWidget
{
    public function type(): string
    {
        return 'bar_chart';
    }

    public function definition(): array
    {
        return [
            'title' => 'Bar Chart',
            'description' => 'Compare categories as horizontal bars or vertical columns.',
            'defaultSize' => ['w' => 6, 'h' => 3],
            'defaultProps' => [
                'label' => 'New bar chart',
                'context' => '',
                'dataset' => 'machine_types',
                'label_key' => 'label',
                'value_key' => 'total',
                'orientation' => 'horizontal',
                'tone' => 'primary',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'context', 'label' => 'Context line', 'type' => 'text'],
                ['key' => 'dataset', 'label' => 'Dataset', 'type' => 'dataset', 'required' => true,
                    'options' => ['regions', 'machine_types', 'top_accounts'],
                    'help' => 'regions: label=region value=products · machine_types: label total · top_accounts: label customer value products'],
                ['key' => 'label_key', 'label' => 'Label column', 'type' => 'text'],
                ['key' => 'value_key', 'label' => 'Value column', 'type' => 'text'],
                ['key' => 'orientation', 'label' => 'Orientation', 'type' => 'select', 'options' => ['horizontal', 'vertical']],
                ['key' => 'tone', 'label' => 'Accent', 'type' => 'select', 'options' => config('dashboard.tones', ['primary'])],
            ],
        ];
    }

    public function resolve(array $props, DashboardContext $ctx): array
    {
        [$rows, $labelKey, $valueKey] = $this->rows($props, $ctx);

        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row[$valueKey]) || ! is_numeric($row[$valueKey])) {
                continue;
            }

            $items[] = [
                'label' => (string) ($row[$labelKey] ?? '—'),
                'value' => (float) $row[$valueKey],
                'href' => isset($row['href']) ? (string) $row['href'] : null,
            ];
        }

        $max = max(1.0, max(array_column($items, 'value') ?: [1.0]));

        return [
            'view' => 'components.dashboard.widgets.bar-chart',
            'data' => [
                'label' => (string) ($props['label'] ?? 'Comparison'),
                'context' => (string) ($props['context'] ?? ''),
                'items' => array_slice($items, 0, 8),
                'max' => $max,
                'orientation' => ($props['orientation'] ?? 'horizontal') === 'vertical' ? 'vertical' : 'horizontal',
                'tone' => (string) ($props['tone'] ?? 'primary'),
                'scope' => $ctx->region,
            ],
        ];
    }

    /**
     * Resolve rows + sensible label/value keys for the chosen dataset, so a
     * user only has to pick a dataset to get a working chart.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: string, 2: string}
     */
    private function rows(array $props, DashboardContext $ctx): array
    {
        $dataset = (string) ($props['dataset'] ?? 'machine_types');
        $rows = $ctx->dataset($dataset);

        $defaults = [
            'regions' => ['region', 'products'],
            'machine_types' => ['label', 'total'],
            'top_accounts' => ['customer', 'products'],
        ];

        [$labelKey, $valueKey] = $defaults[$dataset] ?? ['label', 'total'];

        return [
            $rows,
            (string) ($props['label_key'] ?? '') !== '' ? (string) $props['label_key'] : $labelKey,
            (string) ($props['value_key'] ?? '') !== '' ? (string) $props['value_key'] : $valueKey,
        ];
    }
}
