<?php

namespace App\Support\Dashboard\Widgets;

use App\Support\Dashboard\Contracts\DashboardWidget;
use App\Support\Dashboard\DashboardContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * SLA Countdown: the soonest expiring commitments in scope as a
 * live-ticking countdown queue. The server renders a static d/h fallback
 * for no-JS/print; Alpine ticks the precise d/h/m/s breakdown client-side.
 */
final class SlaCountdownWidget implements DashboardWidget
{
    public function type(): string
    {
        return 'sla_countdown';
    }

    public function definition(): array
    {
        return [
            'title' => 'SLA Countdown',
            'description' => 'Live-ticking countdowns to the soonest expiring commitments.',
            'defaultSize' => ['w' => 4, 'h' => 3],
            'defaultProps' => [
                'label' => 'New countdown',
                'context' => '',
                'metric_label' => 'expiring',
                'limit' => 4,
                'tone' => 'warning',
            ],
            'settings' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['key' => 'context', 'label' => 'Context line', 'type' => 'text'],
                ['key' => 'metric_label', 'label' => 'Counter label', 'type' => 'text', 'placeholder' => 'expiring'],
                ['key' => 'limit', 'label' => 'Rows shown', 'type' => 'number'],
                ['key' => 'tone', 'label' => 'Accent', 'type' => 'select', 'options' => config('dashboard.tones', ['primary'])],
            ],
        ];
    }

    public function resolve(array $props, DashboardContext $ctx): array
    {
        $limit = max(1, (int) ($props['limit'] ?? 4));

        $items = [];

        foreach (array_slice($ctx->dataset('sla'), 0, $limit) as $row) {
            $due = $row['due'] ?? null;

            if (! $due instanceof DateTimeInterface && ! is_string($due)) {
                continue;
            }

            $dueAt = $due instanceof DateTimeInterface ? Carbon::instance($due) : Carbon::parse($due);
            $minutes = max(0, (int) now()->diffInMinutes($dueAt, false));

            $items[] = [
                'label' => (string) ($row['label'] ?? 'Commitment'),
                'href' => isset($row['href']) ? (string) $row['href'] : null,
                'due' => $dueAt,
                'dueIso' => $dueAt->toIso8601String(),
                'overdue' => $minutes <= 0,
                'd' => intdiv($minutes, 1440),
                'h' => intdiv($minutes % 1440, 60),
            ];
        }

        return [
            'view' => 'components.dashboard.widgets.sla-countdown',
            'data' => [
                'label' => (string) ($props['label'] ?? 'Countdown'),
                'context' => (string) ($props['context'] ?? ''),
                'metricLabel' => (string) ($props['metric_label'] ?? 'expiring'),
                'expiring' => count($items),
                'items' => $items,
                'tone' => (string) ($props['tone'] ?? 'warning'),
                'scope' => $ctx->region,
            ],
        ];
    }
}
