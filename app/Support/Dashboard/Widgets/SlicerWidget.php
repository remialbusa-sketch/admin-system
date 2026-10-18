<?php

namespace App\Support\Dashboard\Widgets;

use App\Services\DashboardColumnService;
use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;

final class SlicerWidget implements DashboardWidget
{
    public function __construct(private readonly DashboardColumnService $columns) {}

    public function type(): string
    {
        return 'slicer';
    }

    public function definition(): array
    {
        return [
            'title' => 'Slicer', 'description' => 'Filter charts and metrics from the same table using selectable buttons.',
            'defaultSize' => ['w' => 3, 'h' => 3],
            'defaultProps' => ['label' => 'New slicer', 'column' => '', 'mode' => 'value', 'columns' => 2, 'multiple' => true],
            'settings' => [
                ['key' => 'label', 'label' => 'Title', 'type' => 'text', 'required' => true],
                ['key' => 'column', 'label' => 'Filter column', 'type' => 'column', 'required' => true],
                ['key' => 'mode', 'label' => 'Filter values', 'type' => 'select', 'options' => ['value', 'year', 'month', 'year_month', 'day'], 'help' => 'Use Year and Month on a date column to create separate calendar slicers.'],
                ['key' => 'columns', 'label' => 'Button columns (1–8)', 'type' => 'number'],
                ['key' => 'multiple', 'label' => 'Allow multiple selections', 'type' => 'boolean'],
            ],
        ];
    }

    public function resolve(array $props, DashboardContext $ctx): array
    {
        $props = array_merge($this->definition()['defaultProps'], $props);
        $id = (string) ($props['_widget_id'] ?? '__preview');
        $choices = empty($props['column']) ? ['options' => [], 'selected' => [], 'truncated' => false]
            : $this->columns->choices($props, $ctx->sourceTables(), $ctx->filters, $id);

        return ['view' => 'components.dashboard.widgets.slicer', 'data' => $choices + [
            'label' => $props['label'], 'slicerId' => $id, 'buttonColumns' => min(8, max(1, (int) $props['columns'])),
            'multiple' => (bool) $props['multiple'], 'configured' => ! empty($props['column']),
            'contentHeight' => max(120, (int) ($props['_widget_height'] ?? 3) * 112 - 68),
        ]];
    }
}
