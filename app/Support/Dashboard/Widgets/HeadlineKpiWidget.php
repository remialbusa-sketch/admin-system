<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\ExpressionSyntaxError;

/**
 * Headline KPI: the curated lead/rest cards (Home, TSA) as a real widget —
 * same markup, live values. `variant` picks the lead hero or the standard
 * card; `{period}` in the label resolves to the viewing period (e.g. the
 * "Completed · 30d" window card).
 */
final class HeadlineKpiWidget implements DashboardWidget
{
    public function __construct(private readonly ExpressionEngine $expressions) {}

    public function type(): string
    {
        return 'headline_kpi';
    }

    public function definition(): array
    {
        return [
            'title' => 'Headline KPI',
            'description' => 'Curated headline card with status color and deep link.',
            'defaultSize' => ['w' => 4, 'h' => 2],
            'defaultProps' => [
                'label' => 'Headline',
                'variant' => 'card',
                'metric' => 'installed',
                'formula' => '',
                'suffix' => '',
                'decimals' => 0,
                'context' => '',
                'context_metric' => '',
                'context_prefix' => '',
                'context_suffix' => '',
                'context_decimals' => 0,
                'caption' => '',
                'href' => '',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'variant', 'label' => 'Style', 'type' => 'select', 'options' => ['lead', 'card']],
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
                ['key' => 'href', 'label' => 'Deep link', 'type' => 'text', 'placeholder' => '/installed-products?status=Active'],
                ['key' => 'caption', 'label' => 'Caption', 'type' => 'text', 'help' => 'Small line under the value on the lead style.'],
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

        $decimals = (int) ($props['decimals'] ?? 0);
        $formatted = is_numeric($value) ? number_format((float) $value, $decimals).((string) ($props['suffix'] ?? '')) : '';

        $contextMetric = trim((string) ($props['context_metric'] ?? ''));
        $contextValue = $contextMetric !== '' ? $ctx->metric($contextMetric) : null;
        $context = is_numeric($contextValue)
            ? (string) ($props['context_prefix'] ?? '').number_format((float) $contextValue, (int) ($props['context_decimals'] ?? 0)).((string) ($props['context_suffix'] ?? ''))
            : (string) ($props['context'] ?? '');

        $metric = trim((string) ($props['metric'] ?? ''));

        return [
            'view' => 'components.dashboard.widgets.headline-kpi',
            'data' => [
                'label' => str_replace('{period}', strtolower($ctx->period), (string) ($props['label'] ?? 'Headline')),
                'variant' => ($props['variant'] ?? 'card') === 'lead' ? 'lead' : 'card',
                'value' => $formatted,
                'context' => $context,
                'caption' => (string) ($props['caption'] ?? ''),
                'href' => filled($props['href'] ?? null) ? (string) $props['href'] : null,
                'rag' => $ctx->rag($metric !== '' ? $metric : '___'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
