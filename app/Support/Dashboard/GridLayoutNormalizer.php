<?php

namespace App\Support\Dashboard;

use Illuminate\Support\Str;

/**
 * Validates and clamps a raw layout (user-saved JSON or the config default)
 * into the canonical shape the grid renders. Unknown widget types are
 * dropped, spans are clamped to the 12-column grid, ids are deduped — a
 * tampered layout can never break rendering or inject markup.
 *
 * @phpstan-type NormalizedWidget array{id: string, type: string, w: int, h: int, props: array<string, mixed>}
 */
final class GridLayoutNormalizer
{
    private const MAX_W = 12;
    private const MAX_H = 6;
    private const MAX_WIDGETS = 40;
    private const MAX_ID_LENGTH = 64;

    /**
     * Saved layouts from before the widget-library replacement: old type
     * keys map onto their closest standard widget so users keep a working
     * dashboard instead of an empty grid. Types with no equivalent
     * (ai-insights, risk) are dropped.
     */
    private const LEGACY_TYPES = [
        'kpi' => 'kpi_card',
        'goal' => 'progress',
        'trend' => 'line_chart',
        'pivot' => 'table',
        'sla' => 'sla_countdown',
    ];

    /**
     * @return array{version: int, widgets: array<int, NormalizedWidget>}
     */
    public function normalize(mixed $raw): array
    {
        $knownTypes = array_keys(config('dashboard.widgets', []));
        $widgets = $raw['widgets'] ?? [];
        $seen = [];
        $normalized = [];

        foreach (is_array($widgets) ? $widgets : [] as $widget) {
            if (! is_array($widget) || count($normalized) >= self::MAX_WIDGETS) {
                continue;
            }

            $rawType = $widget['type'] ?? null;

            if (! is_string($rawType)) {
                continue;
            }

            // Map legacy types forward, migrate their props, then validate
            // against the current registry.
            $type = self::LEGACY_TYPES[$rawType] ?? $rawType;

            if (! in_array($type, $knownTypes, true)) {
                continue; // unknown/tampered type: dropped, never rendered
            }

            $id = $widget['id'] ?? null;
            $id = is_string($id) && preg_match('/^[\w\-.:]{1,'.self::MAX_ID_LENGTH.'}$/', $id) === 1
                ? $id
                : $type.'-'.Str::random(6);

            $base = $id;
            $suffix = 2;

            while (isset($seen[$id])) {
                $id = $base.'-'.$suffix++;
            }

            $seen[$id] = true;

            $props = is_array($widget['props'] ?? null) ? $widget['props'] : [];

            if ($rawType !== $type) {
                $props = $this->migrateLegacyProps($rawType, $props);
            }

            $normalized[] = [
                'id' => $id,
                'type' => $type,
                'w' => $this->clampSpan($widget['w'] ?? null, self::MAX_W, 4),
                'h' => $this->clampSpan($widget['h'] ?? null, self::MAX_H, 2),
                'props' => $props,
            ];
        }

        return ['version' => 1, 'widgets' => $normalized];
    }

    /**
     * Carry saved props across the legacy → standard widget rename. Shared
     * keys pass through; renamed/new keys get sensible values.
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function migrateLegacyProps(string $legacyType, array $props): array
    {
        return match ($legacyType) {
            'kpi' => [
                'label' => (string) ($props['label'] ?? 'KPI'),
                'metric' => 'installed',
                'formula' => (string) ($props['formula'] ?? ''),
                'suffix' => (string) ($props['suffix'] ?? ''),
                'decimals' => (int) ($props['decimals'] ?? 0),
                'context' => (string) ($props['context'] ?? ''),
                'href' => (string) ($props['href'] ?? ''),
                'green_above' => 90,
                'amber_above' => 75,
                'sparkline' => true,
                'trend_metric' => 'install_delta',
                'tone' => (string) ($props['tone'] ?? 'primary'),
            ],
            'goal' => [
                'label' => (string) ($props['label'] ?? 'Goal'),
                'context' => (string) ($props['subtitle'] ?? ''),
                'current' => (string) ($props['current'] ?? '0'),
                'goal' => (string) ($props['goal'] ?? '100'),
                'unit' => (string) ($props['unit'] ?? ''),
                'tone' => 'primary',
            ],
            'trend' => [
                'label' => (string) ($props['label'] ?? 'Trend'),
                'context' => (string) ($props['subtitle'] ?? ''),
                'dataset' => (string) ($props['dataset'] ?? 'install_trend'),
                'value_key' => (string) ($props['value_key'] ?? 'count'),
                'area' => true,
                'delta_metric' => (string) ($props['delta_metric'] ?? 'install_delta'),
                'tone' => (string) ($props['tone'] ?? 'primary'),
            ],
            'pivot' => [
                'label' => (string) ($props['label'] ?? 'Data'),
                'context' => (string) ($props['subtitle'] ?? ''),
                'dataset' => 'regions',
                'label_key' => 'region',
                'max_rows' => 8,
                'totals' => true,
                'tone' => 'primary',
            ],
            'sla' => [
                'label' => (string) ($props['label'] ?? 'Countdown'),
                'context' => (string) ($props['subtitle'] ?? ''),
                'metric_label' => (string) ($props['metric_label'] ?? 'expiring'),
                'limit' => (int) ($props['limit'] ?? 4),
                'tone' => (string) ($props['tone'] ?? 'warning'),
            ],
            default => $props,
        };
    }

    private function clampSpan(mixed $value, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return (int) min($max, max(1, (int) $value));
    }
}
