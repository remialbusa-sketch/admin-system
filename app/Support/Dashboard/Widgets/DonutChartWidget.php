<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\ChartPalette;
use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;

/**
 * Donut Chart: composition of a whole — fleet state, brand mix — as SVG
 * arc segments with a legend. Segment colors come from the dataset when it
 * provides them (fleet tones, brand palette), otherwise from the shared
 * chart palette.
 */
final class DonutChartWidget implements DashboardWidget
{
    public function type(): string
    {
        return 'donut_chart';
    }

    public function definition(): array
    {
        return [
            'title' => 'Donut Chart',
            'description' => 'Composition of a whole with a legend — fleet state, brand mix.',
            'defaultSize' => ['w' => 4, 'h' => 3],
            'defaultProps' => [
                'label' => 'New donut',
                'context' => '',
                'dataset' => 'fleet',
                'label_key' => 'label',
                'value_key' => 'value',
                'tone' => 'primary',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'context', 'label' => 'Context line', 'type' => 'text'],
                ['key' => 'dataset', 'label' => 'Dataset', 'type' => 'dataset', 'required' => true,
                    'options' => ['fleet', 'brands'],
                    'help' => 'fleet: label value · brands: label value'],
                ['key' => 'label_key', 'label' => 'Label column', 'type' => 'text'],
                ['key' => 'value_key', 'label' => 'Value column', 'type' => 'text'],
                ['key' => 'tone', 'label' => 'Accent', 'type' => 'select', 'options' => config('dashboard.tones', ['primary'])],
            ],
        ];
    }

    public function resolve(array $props, DashboardContext $ctx): array
    {
        $dataset = (string) ($props['dataset'] ?? 'fleet');
        $rows = $ctx->dataset($dataset);

        $labelKey = filled($props['label_key'] ?? null) ? (string) $props['label_key'] : 'label';
        $valueKey = filled($props['value_key'] ?? null) ? (string) $props['value_key'] : 'value';

        $items = [];

        foreach ($rows as $i => $row) {
            if (! is_array($row) || ! isset($row[$valueKey]) || ! is_numeric($row[$valueKey])) {
                continue;
            }

            $items[] = [
                'label' => (string) ($row[$labelKey] ?? '—'),
                'value' => (float) $row[$valueKey],
                'color' => $row['color'] ?? ChartPalette::color(count($items)),
                'href' => isset($row['href']) ? (string) $row['href'] : null,
            ];
        }

        $total = array_sum(array_column($items, 'value'));

        // SVG arc geometry: r=54 stroke ring, cumulative dash offsets.
        $circumference = 2 * M_PI * 54;
        $offset = 0.0;

        foreach ($items as $i => $item) {
            $fraction = $total > 0 ? $item['value'] / $total : 0.0;
            $items[$i]['dash'] = round($fraction * $circumference, 2);
            $items[$i]['gap'] = round($circumference - $items[$i]['dash'], 2);
            $items[$i]['offset'] = round(-$offset, 2);
            $items[$i]['percent'] = $total > 0 ? round($fraction * 100, 1) : 0.0;
            $offset += $items[$i]['dash'];
        }

        return [
            'view' => 'components.dashboard.widgets.donut-chart',
            'data' => [
                'label' => (string) ($props['label'] ?? 'Composition'),
                'context' => (string) ($props['context'] ?? ''),
                'items' => $items,
                'total' => $total,
                'circumference' => round($circumference, 2),
                'tone' => (string) ($props['tone'] ?? 'primary'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
