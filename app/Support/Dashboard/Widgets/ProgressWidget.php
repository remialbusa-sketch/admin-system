<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\ExpressionEngine;

/**
 * Progress Bar: current value against a goal — both built visually —
 * rendered as a semantic progress bar (met / approaching / behind).
 */
final class ProgressWidget implements DashboardWidget
{
    public function __construct(private readonly ExpressionEngine $expressions) {}

    public function type(): string
    {
        return 'progress';
    }

    public function definition(): array
    {
        return [
            'title' => 'Progress Bar',
            'description' => 'Track a value against its goal with a semantic progress bar.',
            'defaultSize' => ['w' => 4, 'h' => 2],
            'defaultProps' => [
                'label' => 'New goal',
                'context' => '',
                'current' => 'warranty_ratio',
                'goal' => '60',
                'unit' => '%',
                'tone' => 'primary',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'current', 'label' => 'Current value', 'type' => 'expression', 'required' => true, 'visual' => true,
                    'help' => 'Pick a metric or build a formula on the canvas.'],
                ['key' => 'goal', 'label' => 'Goal value', 'type' => 'expression', 'required' => true, 'visual' => true,
                    'help' => 'A metric, a formula, or just a number block.'],
                ['key' => 'unit', 'label' => 'Unit', 'type' => 'text', 'placeholder' => '%'],
                ['key' => 'context', 'label' => 'Context line', 'type' => 'text'],
                ['key' => 'tone', 'label' => 'Accent', 'type' => 'select', 'options' => config('dashboard.tones', ['primary'])],
            ],
        ];
    }

    public function resolve(array $props, DashboardContext $ctx): array
    {
        $variables = $ctx->variables();

        $current = $this->expressions->evaluate((string) ($props['current'] ?? '0'), $variables);
        $goal = $this->expressions->evaluate((string) ($props['goal'] ?? '100'), $variables);

        $currentNum = is_numeric($current) ? (float) $current : 0.0;
        $goalNum = is_numeric($goal) ? (float) $goal : 0.0;

        $percent = $goalNum != 0.0 ? max(0.0, min(100.0, ($currentNum / $goalNum) * 100)) : 0.0;
        $state = $percent >= 100 ? 'met' : ($percent >= 70 ? 'approaching' : 'behind');

        return [
            'view' => 'components.dashboard.widgets.progress',
            'data' => [
                'label' => (string) ($props['label'] ?? 'Goal'),
                'context' => (string) ($props['context'] ?? ''),
                'current' => $currentNum,
                'goal' => $goalNum,
                'percent' => $percent,
                'state' => $state,
                'unit' => (string) ($props['unit'] ?? ''),
                'tone' => (string) ($props['tone'] ?? 'primary'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
