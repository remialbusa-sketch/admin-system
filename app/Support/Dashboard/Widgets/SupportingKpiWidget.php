<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\ExpressionSyntaxError;

/**
 * Supporting KPI: the curated strip rows (Home, TSA, TSP) as a real widget
 * — same markup, live values. Shows a status badge, or an icon tile when
 * the `icon` prop names a Mary icon (TSP strip).
 */
final class SupportingKpiWidget implements DashboardWidget
{
    public function __construct(private readonly ExpressionEngine $expressions) {}

    public function type(): string
    {
        return 'supporting_kpi';
    }

    public function definition(): array
    {
        return [
            'title' => 'Supporting KPI',
            'description' => 'Compact strip row with status badge or icon and deep link.',
            'defaultSize' => ['w' => 4, 'h' => 1],
            'defaultProps' => [
                'label' => 'Supporting metric',
                'metric' => 'installed',
                'formula' => '',
                'suffix' => '',
                'decimals' => 0,
                'context' => '',
                'context_metric' => '',
                'context_prefix' => '',
                'context_suffix' => '',
                'context_decimals' => 0,
                'href' => '',
                'icon' => '',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'metric', 'label' => 'Metric', 'type' => 'metric', 'help' => 'Used when no formula is set.'],
                ['key' => 'formula', 'label' => 'Value formula', 'type' => 'expression', 'visual' => true,
                    'help' => 'Overrides the plain metric.'],
                ['key' => 'suffix', 'label' => 'Suffix', 'type' => 'text', 'placeholder' => '%'],
                ['key' => 'decimals', 'label' => 'Decimals', 'type' => 'number'],
                ['key' => 'context', 'label' => 'Context line', 'type' => 'text'],
                ['key' => 'context_metric', 'label' => 'Context metric', 'type' => 'metric',
                    'help' => 'Live number composed into the context line; empty keeps the static text.'],
                ['key' => 'context_prefix', 'label' => 'Context prefix', 'type' => 'text'],
                ['key' => 'context_suffix', 'label' => 'Context suffix', 'type' => 'text'],
                ['key' => 'context_decimals', 'label' => 'Context decimals', 'type' => 'number'],
                ['key' => 'href', 'label' => 'Deep link', 'type' => 'text'],
                ['key' => 'icon', 'label' => 'Icon', 'type' => 'text', 'placeholder' => 'o-users',
                    'help' => 'Mary icon name for an icon tile instead of the status badge.'],
                ['key' => 'tone', 'label' => 'Accent', 'type' => 'select', 'options' => ['primary', 'info', 'success', 'warning']],
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

        $decimals = (int) ($props['decimals'] ?? 0);
        $formatted = is_numeric($value) ? number_format((float) $value, $decimals).((string) ($props['suffix'] ?? '')) : '';

        $contextMetric = trim((string) ($props['context_metric'] ?? ''));
        $contextValue = $contextMetric !== '' ? $ctx->metric($contextMetric) : null;
        $context = is_numeric($contextValue)
            ? (string) ($props['context_prefix'] ?? '').number_format((float) $contextValue, (int) ($props['context_decimals'] ?? 0)).((string) ($props['context_suffix'] ?? ''))
            : (string) ($props['context'] ?? '');

        $metric = trim((string) ($props['metric'] ?? ''));

        return [
            'view' => 'components.dashboard.widgets.supporting-kpi',
            'data' => [
                'label' => str_replace('{period}', strtolower($ctx->period), (string) ($props['label'] ?? 'Metric')),
                'value' => $formatted,
                'context' => $context,
                'href' => filled($props['href'] ?? null) ? (string) $props['href'] : null,
                'icon' => trim((string) ($props['icon'] ?? '')),
                'tone' => in_array($props['tone'] ?? 'primary', ['primary', 'info', 'success', 'warning'], true) ? $props['tone'] : 'primary',
                'rag' => $ctx->rag($metric !== '' ? $metric : '___'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
