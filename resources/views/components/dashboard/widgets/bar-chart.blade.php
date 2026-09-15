<div class="flex h-full flex-col">
    <x-dashboard.widget-header :tone="$tone" tag="COMPARE" :title="$label" :subtitle="trim($context) !== '' ? $context : $scope" />

    @if (count($items) === 0)
        <div class="mt-4 flex flex-1 flex-col items-start justify-center rounded-lg border border-dashed border-base-300 p-4">
            <p class="text-sm font-semibold text-base-content/70">Nothing to compare yet</p>
            <p class="mt-1 text-xs text-base-content/45">The selected dataset is empty in this scope.</p>
        </div>
    @elseif ($orientation === 'horizontal')
        <div class="admin-scrollbar mt-4 flex-1 space-y-2.5 overflow-y-auto pr-1">
            @foreach ($items as $item)
                <div class="flex items-center gap-3">
                    <span class="w-24 shrink-0 truncate text-xs font-semibold text-base-content/75" title="{{ $item['label'] }}">
                        @if ($item['href'])
                            <a href="{{ $item['href'] }}" wire:navigate class="underline-offset-2 transition hover:text-primary hover:underline">{{ $item['label'] }}</a>
                        @else
                            {{ $item['label'] }}
                        @endif
                    </span>
                    <div class="h-3 min-w-0 flex-1 overflow-hidden rounded-full bg-base-200">
                        <div class="h-full rounded-full" style="width: {{ max(2, round($item['value'] / $max * 100, 1)) }}%; background: var(--color-{{ $tone }})"></div>
                    </div>
                    <span class="w-14 shrink-0 text-right text-xs font-bold tabular-nums text-base-content/80">{{ number_format($item['value'], fmod($item['value'], 1.0) == 0.0 ? 0 : 1) }}</span>
                </div>
            @endforeach
        </div>
    @else
        @php
            $count = max(1, count($items));
            $colW = 56; $gap = 18; $chartH = 150;
            $width = $count * $colW + ($count + 1) * $gap;
        @endphp
        <div class="admin-scrollbar mt-4 flex-1 overflow-x-auto">
            <svg viewBox="0 0 {{ $width }} {{ $chartH + 34 }}" class="h-full max-h-48 w-full min-w-[280px]" role="img" aria-label="{{ $label }} column chart">
                @foreach ($items as $i => $item)
                    @php
                        $h = max(3, round($item['value'] / $max * $chartH));
                        $x = $gap + $i * ($colW + $gap);
                        $y = $chartH - $h;
                    @endphp
                    @if ($item['href'])
                        <a href="{{ $item['href'] }}" wire:navigate><rect x="{{ $x }}" y="{{ $y }}" width="{{ $colW }}" height="{{ $h }}" rx="6" fill="var(--color-{{ $tone }})" opacity=".9" /></a>
                    @else
                        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $colW }}" height="{{ $h }}" rx="6" fill="var(--color-{{ $tone }})" opacity=".9" />
                    @endif
                    <text x="{{ $x + $colW / 2 }}" y="{{ $y - 6 }}" text-anchor="middle" style="font-size: 11px; font-weight: 700" class="fill-base-content">{{ number_format($item['value'], fmod($item['value'], 1.0) == 0.0 ? 0 : 1) }}</text>
                    <text x="{{ $x + $colW / 2 }}" y="{{ $chartH + 16 }}" text-anchor="middle" style="font-size: 9px" class="fill-base-content" opacity=".55">
                        {{ \Illuminate\Support\Str::limit($item['label'], 9) }}
                        <title>{{ $item['label'] }}</title>
                    </text>
                @endforeach
            </svg>
        </div>
    @endif
</div>
