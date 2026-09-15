<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\ExpressionSyntaxError;
use App\Support\Dashboard\SvgTrend;

/**
 * KPI Card: a single headline number — plain metric or a visually-built
 * formula — with threshold status (green/amber/red), an optional sparkline
 * of the installation trend, and a momentum arrow.
 */
final class KpiCardWidget implements DashboardWidget
{
    public function __construct(private readonly ExpressionEngine $expressions) {}

    public function type(): string
    {
        return 'kpi_card';
    }

    public function definition(): array
    {
        return [
            'title' => 'KPI Card',
            'description' => 'Headline number with status color, sparkline and momentum arrow.',
            'defaultSize' => ['w' => 4, 'h' => 2],
            'defaultProps' => [
                'label' => 'New KPI',
                'metric' => 'installed',
                'formula' => '',
                'suffix' => '',
                'decimals' => 0,
                'context' => '',
                'href' => '',
                'green_above' => 90,
                'amber_above' => 75,
                'sparkline' => true,
                'trend_metric' => 'install_delta',
                'tone' => 'primary',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'metric', 'label' => 'Metric', 'type' => 'metric',
                    'help' => 'Used when no formula is set.'],
                ['key' => 'formula', 'label' => 'Value formula', 'type' => 'expression', 'visual' => true,
                    'help' => 'Build it visually below — drag blocks on the canvas. Overrides the plain metric.'],
                ['key' => 'suffix', 'label' => 'Suffix', 'type' => 'text', 'placeholder' => '%'],
                ['key' => 'decimals', 'label' => 'Decimals', 'type' => 'number'],
                ['key' => 'context', 'label' => 'Context line', 'type' => 'text'],
                ['key' => 'href', 'label' => 'Deep link', 'type' => 'text', 'placeholder' => '/installed-products?status=Active'],
                ['key' => 'green_above', 'label' => 'Green from', 'type' => 'number', 'help' => 'Status is green when the value is at or above this.'],
                ['key' => 'amber_above', 'label' => 'Amber from', 'type' => 'number', 'help' => 'Below this the status turns red.'],
                ['key' => 'sparkline', 'label' => 'Show sparkline', 'type' => 'boolean'],
                ['key' => 'trend_metric', 'label' => 'Momentum metric', 'type' => 'metric', 'help' => 'Positive values draw an up arrow, negative a down arrow.'],
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

        // null from a formula (empty scope, division by zero) is "no data";
        // a missing plain metric is a config mistake.
        if ($value === null && ! $hasFormula) {
            throw new ExpressionSyntaxError(sprintf(
                "The metric '%s' is not available in this scope.",
                (string) ($props['metric'] ?? '?'),
            ));
        }

        $greenAbove = is_numeric($props['green_above'] ?? null) ? (float) $props['green_above'] : null;
        $amberAbove = is_numeric($props['amber_above'] ?? null) ? (float) $props['amber_above'] : null;

        $status = 'neutral';

        if (is_numeric($value) && $greenAbove !== null && $amberAbove !== null) {
            $status = $value >= $greenAbove ? 'good' : ($value >= $amberAbove ? 'watch' : 'risk');
        }

        $trend = null;

        if (isset($props['trend_metric']) && trim((string) $props['trend_metric']) !== '') {
            $delta = $this->expressions->evaluate((string) $props['trend_metric'], $variables);

            if (is_numeric($delta)) {
                $trend = ['direction' => $delta >= 0 ? 'up' : 'down', 'value' => (float) $delta];
            }
        }

        // Sparkline: the installation trend series in this scope.
        $spark = ['line' => '', 'area' => ''];

        if ($props['sparkline'] ?? false) {
            $series = array_column($ctx->dataset('install_trend'), 'count');
            $spark = SvgTrend::build(array_map('floatval', $series), 220, 44, 4);
        }

        return [
            'view' => 'components.dashboard.widgets.kpi-card',
            'data' => [
                'label' => (string) ($props['label'] ?? 'Metric'),
                'value' => $value,
                'decimals' => (int) ($props['decimals'] ?? 0),
                'suffix' => (string) ($props['suffix'] ?? ''),
                'context' => (string) ($props['context'] ?? ''),
                'href' => filled($props['href'] ?? null) ? (string) $props['href'] : null,
                'status' => $status,
                'trend' => $trend,
                'spark' => $props['sparkline'] ?? false ? $spark : null,
                'tone' => (string) ($props['tone'] ?? 'primary'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
