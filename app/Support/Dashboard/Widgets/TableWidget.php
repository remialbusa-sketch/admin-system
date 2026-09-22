<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;
use Illuminate\Support\Str;

/**
 * Data Table: a clean grid over any label/value dataset — regional
 * position, top accounts, machine mix — with auto-detected numeric columns
 * and a totals row.
 */
final class TableWidget implements DashboardWidget
{
    public function type(): string
    {
        return 'table';
    }

    public function definition(): array
    {
        return [
            'title' => 'Data Table',
            'description' => 'Sortable-looking grid with auto columns and a totals row.',
            'defaultSize' => ['w' => 6, 'h' => 3],
            'defaultProps' => [
                'label' => 'New table',
                'context' => '',
                'dataset' => 'regions',
                'label_key' => 'region',
                'max_rows' => 8,
                'totals' => true,
                'tone' => 'primary',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'context', 'label' => 'Context line', 'type' => 'text'],
                ['key' => 'dataset', 'label' => 'Dataset', 'type' => 'dataset', 'required' => true,
                    'options' => ['regions', 'top_accounts', 'machine_types'],
                    'help' => 'Numeric columns are detected from the rows automatically.'],
                ['key' => 'label_key', 'label' => 'First column', 'type' => 'text', 'help' => 'regions/top_accounts: region or customer · machine_types: label'],
                ['key' => 'max_rows', 'label' => 'Max rows', 'type' => 'number'],
                ['key' => 'totals', 'label' => 'Show totals row', 'type' => 'boolean'],
                ['key' => 'tone', 'label' => 'Accent', 'type' => 'select', 'options' => config('dashboard.tones', ['primary'])],
            ],
        ];
    }

    public function resolve(array $props, DashboardContext $ctx): array
    {
        $dataset = (string) ($props['dataset'] ?? 'regions');
        $rows = $ctx->dataset($dataset);

        $baseDataset = Str::afterLast($dataset, '.');

        $labelKey = filled($props['label_key'] ?? null) ? (string) $props['label_key']
            : match ($baseDataset) {
                'top_accounts' => 'customer',
                'machine_types' => 'label',
                'regions' => 'region',
                default => 'label',
            };

        $maxRows = max(1, (int) ($props['max_rows'] ?? 8));

        // First label-ish column + up to four numeric columns, order kept.
        $valueKeys = [];
        $headings = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $key => $value) {
                if ($key === $labelKey || $key === 'href' || isset($headings[$key])) {
                    continue;
                }

                if (is_numeric($value)) {
                    $headings[$key] = true;
                    $valueKeys[] = $key;
                }
            }

            if (count($valueKeys) >= 4) {
                break;
            }
        }

        $valueKeys = array_slice($valueKeys, 0, 4);

        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row[$labelKey])) {
                continue;
            }

            $cells = [];

            foreach ($valueKeys as $key) {
                $cells[$key] = is_numeric($row[$key] ?? null) ? (float) $row[$key] : null;
            }

            $items[] = [
                'label' => (string) $row[$labelKey],
                'cells' => $cells,
                'href' => isset($row['href']) ? (string) $row['href'] : null,
            ];
        }

        $totals = [];

        if ($props['totals'] ?? true) {
            foreach ($valueKeys as $key) {
                $totals[$key] = array_sum(array_map(fn (array $item) => $item['cells'][$key] ?? 0, $items));
            }
        }

        return [
            'view' => 'components.dashboard.widgets.table',
            'data' => [
                'label' => (string) ($props['label'] ?? 'Data'),
                'context' => (string) ($props['context'] ?? ''),
                'columns' => array_map(fn (string $key) => [
                    'key' => $key,
                    'heading' => ucfirst(str_replace('_', ' ', $key)),
                ], $valueKeys),
                'items' => array_slice($items, 0, $maxRows),
                'totals' => $totals,
                'tone' => (string) ($props['tone'] ?? 'primary'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
