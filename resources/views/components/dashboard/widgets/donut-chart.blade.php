<div class="flex h-full flex-col">
    <x-dashboard.widget-header :tone="$tone" tag="SHARE" :title="$label" :subtitle="trim($context) !== '' ? $context : $scope" />

    @if (count($items) === 0)
        <div class="mt-4 flex flex-1 flex-col items-start justify-center rounded-lg border border-dashed border-base-300 p-4">
            <p class="text-sm font-semibold text-base-content/70">Nothing to break down yet</p>
            <p class="mt-1 text-xs text-base-content/45">The selected dataset is empty in this scope.</p>
        </div>
    @else
        <div class="mt-4 flex flex-1 items-center gap-5">
            <svg viewBox="0 0 140 140" class="h-32 w-32 shrink-0" role="img" aria-label="{{ $label }} donut chart">
                @foreach ($items as $item)
                    <circle cx="70" cy="70" r="54" fill="none" stroke="{{ $item['color'] }}" stroke-width="20"
                            stroke-dasharray="{{ $item['dash'] }} {{ $item['gap'] }}" stroke-dashoffset="{{ $item['offset'] }}"
                            transform="rotate(-90 70 70)" />
                @endforeach
                <text x="70" y="66" text-anchor="middle" style="font-size: 24px; font-weight: 700" class="fill-base-content">{{ number_format($total, fmod($total, 1.0) == 0.0 ? 0 : 1) }}</text>
                <text x="70" y="84" text-anchor="middle" style="font-size: 9px; font-weight: 700; letter-spacing: .12em" class="fill-base-content" opacity=".45">TOTAL</text>
            </svg>
            <ul class="admin-scrollbar min-w-0 flex-1 space-y-1.5 overflow-y-auto">
                @foreach ($items as $item)
                    <li class="flex items-center gap-2 text-xs">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $item['color'] }}" aria-hidden="true"></span>
                        @if ($item['href'])
                            <a href="{{ $item['href'] }}" wire:navigate class="min-w-0 flex-1 truncate font-semibold underline-offset-2 transition hover:text-primary hover:underline" title="{{ $item['label'] }}">{{ $item['label'] }}</a>
                        @else
                            <span class="min-w-0 flex-1 truncate font-semibold text-base-content/80" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                        @endif
                        <span class="shrink-0 tabular-nums text-base-content/55">{{ number_format($item['value'], fmod($item['value'], 1.0) == 0.0 ? 0 : 1) }} · {{ $item['percent'] }}%</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
