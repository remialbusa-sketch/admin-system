@php
    $statusStyles = [
        'good' => ['chip' => 'bg-success/15 text-success', 'glyph' => '✓', 'title' => 'on track'],
        'watch' => ['chip' => 'bg-warning/15 text-warning-content', 'glyph' => '!', 'title' => 'watch'],
        'risk' => ['chip' => 'bg-error/15 text-error', 'glyph' => '✕', 'title' => 'critical'],
        'neutral' => ['chip' => 'bg-base-200 text-base-content/50', 'glyph' => '·', 'title' => 'no thresholds set'],
    ];
    $statusStyle = $statusStyles[$status] ?? $statusStyles['neutral'];
    $isNumeric = is_numeric($value);
@endphp
<div class="flex h-full flex-col">
    <x-dashboard.widget-header :tone="$tone" tag="KPI" :title="$label" :subtitle="$scope" />
    <div class="mt-auto pt-5">
        <div class="flex items-end justify-between gap-3">
            <p class="metric-value text-5xl font-semibold leading-none tracking-tight">
                @if ($value === null)
                    <span class="text-base-content/30" title="No data in this scope yet">—</span>
                @elseif ($isNumeric)
                    {{ number_format((float) $value, $decimals) }}<span class="text-2xl text-base-content/55">{{ $suffix }}</span>
                @else
                    {{ $value }}
                @endif
            </p>
            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-black {{ $statusStyle['chip'] }}" title="{{ $statusStyle['title'] }}">{{ $statusStyle['glyph'] }}</span>
        </div>

        @if ($trend)
            <p class="mt-2 flex items-center gap-1 text-[11px] font-bold {{ $trend['direction'] === 'up' ? 'text-success' : 'text-error' }}">
                <span aria-hidden="true">{{ $trend['direction'] === 'up' ? '▲' : '▼' }}</span>
                {{ number_format(abs($trend['value']), 1) }}% vs prior window
            </p>
        @endif

        @if (trim($context) !== '')
            <p class="mt-1.5 truncate text-[11px] text-base-content/45">{{ $context }}</p>
        @endif

        @if ($spark !== null && $spark['line'] !== '')
            <svg viewBox="0 0 220 44" class="mt-3 h-11 w-full" preserveAspectRatio="none" aria-hidden="true">
                <path d="{{ $spark['area'] }}" fill="color-mix(in oklab, var(--color-{{ $tone }}) 14%, transparent)" />
                <path d="{{ $spark['line'] }}" fill="none" stroke="var(--color-{{ $tone }})" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        @endif

        @if ($href)
            <a href="{{ $href }}" wire:navigate class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold text-base-content/50 underline-offset-2 transition hover:text-primary hover:underline">Open records <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5" /></a>
        @endif
    </div>
</div>
