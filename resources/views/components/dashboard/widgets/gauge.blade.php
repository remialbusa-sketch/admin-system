@php
    // Semicircle gauge geometry: center (110, 105), r=84 — arc length πr.
    $radius = 84;
    $arcLength = M_PI * $radius;
    $fill = $arcLength * ($percent / 100);
    $bandTone = $band === 'good' ? 'success' : ($band === 'watch' ? 'warning' : ($band === 'risk' ? 'error' : $tone));

    // Threshold tick positions along the arc (angle from 180° → 0°).
    $tick = function (mixed $threshold) use ($radius, $max): ?array {
        if (! is_numeric($threshold) || ! $max) {
            return null;
        }

        $fraction = max(0.0, min(1.0, $threshold / $max));
        $angle = M_PI * (1 - $fraction);

        return [
            'x1' => 110 + ($radius - 9) * cos($angle),
            'y1' => 105 - ($radius - 9) * sin($angle),
            'x2' => 110 + ($radius + 9) * cos($angle),
            'y2' => 105 - ($radius + 9) * sin($angle),
        ];
    };
    $greenTick = $tick($greenAbove);
    $amberTick = $tick($amberAbove);
@endphp
<div class="flex h-full flex-col">
    <x-dashboard.widget-header :tone="$tone" tag="GAUGE" :title="$label" :subtitle="$scope" />
    <div class="mt-auto flex flex-col items-center pt-2">
        <svg viewBox="0 0 220 128" class="w-full max-w-[240px]" role="img" aria-label="{{ $label }}: {{ $value === null ? 'no data' : number_format($value, 1).' of '.number_format($max, 0) }}">
            <path d="M 26 105 A {{ $radius }} {{ $radius }} 0 0 1 194 105" fill="none" stroke="var(--color-base-200)" stroke-width="15" stroke-linecap="round" />
            @if ($value !== null)
                <path d="M 26 105 A {{ $radius }} {{ $radius }} 0 0 1 194 105"
                      fill="none" stroke="var(--color-{{ $bandTone }})" stroke-width="15" stroke-linecap="round"
                      stroke-dasharray="{{ number_format($fill, 1) }} {{ number_format($arcLength, 1) }}" />
            @endif
            @if ($greenTick)
                <line x1="{{ $greenTick['x1'] }}" y1="{{ $greenTick['y1'] }}" x2="{{ $greenTick['x2'] }}" y2="{{ $greenTick['y2'] }}" stroke="var(--color-success)" stroke-width="2.5" opacity=".85" />
            @endif
            @if ($amberTick)
                <line x1="{{ $amberTick['x1'] }}" y1="{{ $amberTick['y1'] }}" x2="{{ $amberTick['x2'] }}" y2="{{ $amberTick['y2'] }}" stroke="var(--color-warning)" stroke-width="2.5" opacity=".85" />
            @endif
            <text x="110" y="92" text-anchor="middle" class="fill-base-content" style="font-size: 30px; font-weight: 700">
                {{ $value === null ? '—' : number_format($value, fmod((float) $value, 1.0) == 0.0 ? 0 : 1) }}<tspan style="font-size: 15px; fill: var(--color-base-content); opacity: .5">{{ $unit }}</tspan>
            </text>
        </svg>
        <div class="mt-1 flex w-full max-w-[240px] items-center justify-between text-[10px] font-semibold uppercase tracking-wider text-base-content/40">
            <span>0{{ $unit }}</span>
            @if ($amberTick !== null && $greenTick !== null)
                <span class="text-warning-content/70">amber {{ number_format($amberAbove, 0) }}</span>
                <span class="text-success/80">green {{ number_format($greenAbove, 0) }}</span>
            @endif
            <span>{{ number_format($max, 0) }}{{ $unit }}</span>
        </div>
    </div>
</div>
