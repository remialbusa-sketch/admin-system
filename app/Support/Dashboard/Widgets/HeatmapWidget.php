<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\ExpressionEngine;
use InvalidArgumentException;

/**
 * Conditional Heatmap: rows from a dataset, columns defined as expressions,
 * per-cell fill intensity scaled against a pinned max (percent columns) or
 * the column maximum in scope. Pure theme-aware color-mix fills — no
 * hardcoded colors, legible in both light and dark themes.
 */
final class HeatmapWidget implements DashboardWidget
{
    private const MAX_FILL_PERCENT = 72;
    private const MIN_FILL_PERCENT = 6;

    public function __construct(private readonly ExpressionEngine $expressions) {}

    public function type(): string
    {
        return 'heatmap';
    }

    public function definition(): array
    {
        return [
            'title' => 'Heatmap',
            'description' => 'Color-intensity matrix — rows vs expression columns.',
            'defaultSize' => ['w' => 6, 'h' => 3],
            'defaultProps' => [
                'label' => 'New heatmap',
                'context' => '',
                'dataset' => 'regions',
                'label_key' => 'region',
                'tone' => 'primary',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'context', 'label' => 'Context line', 'type' => 'text'],
                ['key' => 'dataset', 'label' => 'Dataset', 'type' => 'dataset', 'required' => true,
                    'options' => ['regions'], 'help' => 'Columns come from the dashboard heatmap configuration.'],
                ['key' => 'label_key', 'label' => 'Row label column', 'type' => 'text'],
                ['key' => 'tone', 'label' => 'Accent', 'type' => 'select', 'options' => config('dashboard.tones', ['primary'])],
            ],
        ];
    }

    public function resolve(array $props, DashboardContext $ctx): array
    {
        $dataset = (string) ($props['dataset'] ?? 'regions');
        $rows = $ctx->dataset($dataset);
        $labelKey = filled($props['label_key'] ?? null) ? (string) $props['label_key'] : 'region';

        $columns = config('dashboard.heatmap_columns', []);
        $table = [];
        $scopeMax = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row[$labelKey])) {
                continue;
            }

            // Row variables shadow the global scope: region, products,
            // active, warranty (plus every global metric).
            $variables = [...$ctx->variables(), ...array_intersect_key($row, array_flip([
                'region', 'products', 'active', 'warranty',
            ]))];

            $cells = [];

            foreach ($columns as $column) {
                $value = null;

                try {
                    $evaluated = $this->expressions->evaluate((string) ($column['expr'] ?? ''), $variables);
                    $value = is_numeric($evaluated) ? (float) $evaluated : null;
                } catch (InvalidArgumentException) {
                    $value = null;
                }

                if ($value !== null) {
                    $scopeMax[$column['expr']] = max($scopeMax[$column['expr']] ?? 0.0, $value);
                }

                $cells[] = [
                    'value' => $value,
                    'max' => is_numeric($column['max'] ?? null) ? (float) $column['max'] : null,
                    'tone' => (string) ($column['tone'] ?? 'primary'),
                ];
            }

            $table[] = [
                'label' => (string) $row[$labelKey],
                'href' => isset($row['href']) ? (string) $row['href'] : null,
                'cells' => $cells,
            ];
        }

        // Intensity: pinned max wins, otherwise scale against the column's
        // in-scope maximum; tiny values stay visible.
        foreach ($table as $i => $row) {
            foreach ($row['cells'] as $j => $cell) {
                $expr = $columns[$j]['expr'] ?? '';
                $scale = $cell['max'] ?? ($scopeMax[$expr] ?: 1.0);
                $intensity = $cell['value'] === null ? 0.0 : max(0.0, min(1.0, $cell['value'] / max(1e-9, $scale)));
                $table[$i]['cells'][$j]['intensity'] = (int) round(
                    self::MIN_FILL_PERCENT + $intensity * (self::MAX_FILL_PERCENT - self::MIN_FILL_PERCENT),
                );
            }
        }

        return [
            'view' => 'components.dashboard.widgets.heatmap',
            'data' => [
                'label' => (string) ($props['label'] ?? 'Heatmap'),
                'context' => (string) ($props['context'] ?? ''),
                'columns' => array_map(fn (array $column) => (string) ($column['label'] ?? '?'), $columns),
                'rows' => $table,
                'tone' => (string) ($props['tone'] ?? 'primary'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
