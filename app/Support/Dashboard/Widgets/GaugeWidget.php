<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\ExpressionSyntaxError;

/**
 * Gauge: a semicircle gauge for a 0..max value with green/amber threshold
 * ticks — a speedometer-style read for ratios and SLA posture.
 */
final class GaugeWidget implements DashboardWidget
{
    public function __construct(private readonly ExpressionEngine $expressions) {}

    public function type(): string
    {
        return 'gauge';
    }

    public function definition(): array
    {
        return [
            'title' => 'Gauge',
            'description' => 'Speedometer-style gauge with threshold bands.',
            'defaultSize' => ['w' => 4, 'h' => 3],
            'defaultProps' => [
                'label' => 'New gauge',
                'metric' => 'active_ratio',
                'formula' => '',
                'unit' => '%',
                'max' => 100,
                'green_above' => 90,
                'amber_above' => 75,
                'tone' => 'primary',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'metric', 'label' => 'Metric', 'type' => 'metric', 'help' => 'Used when no formula is set.'],
                ['key' => 'formula', 'label' => 'Formula', 'type' => 'expression', 'visual' => true,
                    'help' => 'Optional: build a formula visually to override the metric.'],
                ['key' => 'unit', 'label' => 'Unit', 'type' => 'text', 'placeholder' => '%'],
                ['key' => 'max', 'label' => 'Gauge maximum', 'type' => 'number', 'help' => 'The value the full arc represents.'],
                ['key' => 'green_above', 'label' => 'Green from', 'type' => 'number'],
                ['key' => 'amber_above', 'label' => 'Amber from', 'type' => 'number'],
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

        $max = is_numeric($props['max'] ?? null) && (float) $props['max'] > 0 ? (float) $props['max'] : 100.0;
        $numeric = is_numeric($value) ? (float) $value : null;
        $percent = $numeric === null ? 0.0 : max(0.0, min(100.0, ($numeric / $max) * 100));

        $greenAbove = is_numeric($props['green_above'] ?? null) ? (float) $props['green_above'] : null;
        $amberAbove = is_numeric($props['amber_above'] ?? null) ? (float) $props['amber_above'] : null;

        $band = 'neutral';

        if ($numeric !== null && $greenAbove !== null && $amberAbove !== null) {
            $band = $numeric >= $greenAbove ? 'good' : ($numeric >= $amberAbove ? 'watch' : 'risk');
        }

        return [
            'view' => 'components.dashboard.widgets.gauge',
            'data' => [
                'label' => (string) ($props['label'] ?? 'Gauge'),
                'value' => $numeric,
                'percent' => $percent,
                'max' => $max,
                'band' => $band,
                'unit' => (string) ($props['unit'] ?? ''),
                'greenAbove' => $greenAbove,
                'amberAbove' => $amberAbove,
                'tone' => (string) ($props['tone'] ?? 'primary'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
