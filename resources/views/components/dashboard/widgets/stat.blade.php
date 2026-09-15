@php
    $isNumeric = is_numeric($value);
@endphp
<div class="flex h-full flex-col">
    <x-dashboard.widget-header :tone="$tone" tag="STAT" :title="$label" :subtitle="$scope" />
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
            @if ($delta)
                <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold {{ $delta['direction'] === 'up' ? 'bg-success/15 text-success' : 'bg-error/15 text-error' }}"
                      title="Change vs the prior window">
                    <span aria-hidden="true">{{ $delta['direction'] === 'up' ? '▲' : '▼' }}</span>{{ number_format(abs($delta['value']), 1) }}%
                </span>
            @endif
        </div>
        @if (trim($context) !== '')
            <p class="mt-2 truncate text-[11px] text-base-content/45">{{ $context }}</p>
        @endif
    </div>
</div>
