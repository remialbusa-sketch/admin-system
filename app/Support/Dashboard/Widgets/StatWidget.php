<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\ExpressionSyntaxError;

/**
 * Stat Card: the minimal "big number" — label, value, optional suffix and a
 * delta arrow. No status thresholds, no sparkline: for the full-featured
 * version see KpiCardWidget.
 */
final class StatWidget implements DashboardWidget
{
    public function __construct(private readonly ExpressionEngine $expressions) {}

    public function type(): string
    {
        return 'stat';
    }

    public function definition(): array
    {
        return [
            'title' => 'Stat Card',
            'description' => 'Big number with label and a change arrow. The simplest widget.',
            'defaultSize' => ['w' => 4, 'h' => 2],
            'defaultProps' => [
                'label' => 'New stat',
                'metric' => 'installed',
                'formula' => '',
                'suffix' => '',
                'decimals' => 0,
                'delta_metric' => 'install_delta',
                'context' => '',
                'tone' => 'primary',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'metric', 'label' => 'Metric', 'type' => 'metric', 'help' => 'Used when no formula is set.'],
                ['key' => 'formula', 'label' => 'Formula', 'type' => 'expression', 'visual' => true,
                    'help' => 'Optional: build a formula visually to override the metric.'],
                ['key' => 'suffix', 'label' => 'Suffix', 'type' => 'text', 'placeholder' => '%'],
                ['key' => 'decimals', 'label' => 'Decimals', 'type' => 'number'],
                ['key' => 'delta_metric', 'label' => 'Change metric', 'type' => 'metric',
                    'help' => 'Optional: positive values draw an up arrow, negative a down arrow.'],
                ['key' => 'context', 'label' => 'Context line', 'type' => 'text'],
                ['key' => 'tone', 'label' => 'Accent', 'type' => 'select', 'options' => config('dashboard.tones', ['primary'])],
            ],
        ];
    }

    public function resolve(array $props, DashboardContext $ctx): array
    {
        $variables = $ctx->variables();
        $hasFormula = isset($props['formula']) && trim((string) $props['formula']) !== '';

        $value = $hasFormula
            ? $this->expressions->evaluate((string) $props['formula'], $variables)
            : $ctx->metric((string) ($props['metric'] ?? ''));

        if ($value === null && ! $hasFormula) {
            throw new ExpressionSyntaxError(sprintf(
                "The metric '%s' is not available in this scope.",
                (string) ($props['metric'] ?? '?'),
            ));
        }

        $delta = null;

        if (isset($props['delta_metric']) && trim((string) $props['delta_metric']) !== '') {
            $evaluated = $this->expressions->evaluate((string) $props['delta_metric'], $variables);

            if (is_numeric($evaluated)) {
                $delta = ['direction' => $evaluated >= 0 ? 'up' : 'down', 'value' => (float) $evaluated];
            }
        }

        return [
            'view' => 'components.dashboard.widgets.stat',
            'data' => [
                'label' => (string) ($props['label'] ?? 'Stat'),
                'value' => $value,
                'decimals' => (int) ($props['decimals'] ?? 0),
                'suffix' => (string) ($props['suffix'] ?? ''),
                'delta' => $delta,
                'context' => (string) ($props['context'] ?? ''),
                'tone' => (string) ($props['tone'] ?? 'primary'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
