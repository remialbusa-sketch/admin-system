<div class="flex h-full flex-col">
    <div class="flex items-start justify-between gap-3">
        <x-dashboard.widget-header :tone="$tone" tag="TREND" :title="$label" :subtitle="trim($context) !== '' ? $context : $scope" />
        @if ($delta)
            <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold {{ $delta['direction'] === 'up' ? 'bg-success/15 text-success' : 'bg-error/15 text-error' }}">
                <span aria-hidden="true">{{ $delta['direction'] === 'up' ? '▲' : '▼' }}</span>{{ number_format(abs($delta['value']), 1) }}%
            </span>
        @endif
    </div>

    @if ($chart['line'] !== '')
        <div class="mt-4 flex-1">
            <svg viewBox="0 0 640 140" class="h-full max-h-44 w-full" preserveAspectRatio="none" role="img" aria-label="{{ $label }} line chart">
                @if ($area)
                    <path d="{{ $chart['area'] }}" fill="color-mix(in oklab, var(--color-{{ $tone }}) 14%, transparent)" />
                @endif
                <path d="{{ $chart['line'] }}" fill="none" stroke="var(--color-{{ $tone }})" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                @foreach ($chart['dots'] as $i => $dot)
                    @php $href = $hrefs[$i] ?? null; @endphp
                    @if ($href)
                        <a href="{{ $href }}" wire:navigate>
                            <circle cx="{{ $dot[0] }}" cy="{{ $dot[1] }}" r="4" fill="var(--color-{{ $tone }})" stroke="var(--color-base-100)" stroke-width="1.5">
                                <title>{{ $labels[$i] ?? '' }}: {{ number_format($values[$i] ?? 0, fmod($values[$i] ?? 0, 1.0) == 0.0 ? 0 : 1) }}</title>
                            </circle>
                        </a>
                    @else
                        <circle cx="{{ $dot[0] }}" cy="{{ $dot[1] }}" r="3.5" fill="var(--color-{{ $tone }})" stroke="var(--color-base-100)" stroke-width="1.5">
                            <title>{{ $labels[$i] ?? '' }}: {{ number_format($values[$i] ?? 0, fmod($values[$i] ?? 0, 1.0) == 0.0 ? 0 : 1) }}</title>
                        </circle>
                    @endif
                @endforeach
            </svg>
            @if (count($labels) > 1 && count($labels) <= 14)
                <div class="mt-1 flex justify-between text-[10px] font-semibold uppercase tracking-wider text-base-content/40">
                    @foreach ($labels as $labelText)
                        <span>{{ $labelText }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        <div class="mt-4 flex flex-1 flex-col items-start justify-center rounded-lg border border-dashed border-base-300 p-4">
            <p class="text-sm font-semibold text-base-content/70">Not enough points yet</p>
            <p class="mt-1 text-xs text-base-content/45">The selected dataset has fewer than two values in this scope — new imports will draw the line.</p>
        </div>
    @endif
</div>
