@php
    $colors = ['#2563eb', '#0d9488', '#d97706', '#9333ea', '#e11d48', '#475569', '#65a30d', '#0891b2', '#c026d3', '#ea580c'];
    $numbers = array_filter(array_merge(...(array_column($points, 'values') ?: [[]])), fn ($v) => $v !== null);
    $low = min(0, ...($numbers ?: [0]));
    $high = max(1, ...($numbers ?: [1]));
    $range = $high - $low;
    $isHorizontal = $horizontal && $chartType === 'bar';
    $seriesCount = max(1, count($series));
    $width = $isHorizontal ? 800 : max(520, count($points) * 65 + 90);
    $groupHeight = max(34, $seriesCount * 18 + 10);
    $height = $isHorizontal ? max(220, count($points) * $groupHeight + 50) : 350;
    $plotLeft = $isHorizontal ? 200 : 65;
    $plotRight = $width - 70;
    $plotTop = 25;
    $plotBottom = $isHorizontal ? $height - 30 : 270;
    $xValue = fn ($v) => $plotLeft + ($v - $low) / $range * ($plotRight - $plotLeft);
    $yValue = fn ($v) => $plotBottom - ($v - $low) / $range * ($plotBottom - $plotTop);
    $step = ($plotRight - $plotLeft) / max(1, count($points));
    $formatValue = fn ($v) => rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.');
@endphp
<div class="flex min-h-0 flex-col" style="height: {{ $contentHeight }}px">
    <h3 class="text-sm font-bold text-base-content">{{ $label }}</h3>
    @if (! $configured)
        <p class="mt-4 text-sm text-base-content/50">Choose columns from a connected table in Widget settings.</p>
    @elseif (! $points)
        <p class="mt-4 text-sm text-base-content/50">No records match the current filters.</p>
    @else
        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-base-content/70">
            @foreach ($series as $s => $name)
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" style="background: {{ $colors[$s % count($colors)] }}"></span>{{ $name }}</span>
            @endforeach
        </div>
        <div class="mt-2 min-h-0 flex-1 overflow-auto" tabindex="0" aria-label="Scrollable chart: {{ $label }}">
            <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $label }}" style="width: 100%; min-width: {{ $width > 800 ? $width : 0 }}px; height: {{ $height }}px" class="text-base-content">
                <title>{{ $label }}</title>
                @for ($tick = 0; $tick <= 4; $tick++)
                    @php $value = $low + $range * $tick / 4; @endphp
                    @if ($isHorizontal)
                        <line x1="{{ $xValue($value) }}" x2="{{ $xValue($value) }}" y1="{{ $plotTop }}" y2="{{ $plotBottom }}" stroke="currentColor" opacity="0.1" />
                        <text x="{{ $xValue($value) }}" y="{{ $height - 8 }}" text-anchor="middle" fill="currentColor" font-size="11">{{ $formatValue($value) }}</text>
                    @else
                        <line x1="{{ $plotLeft }}" x2="{{ $plotRight }}" y1="{{ $yValue($value) }}" y2="{{ $yValue($value) }}" stroke="currentColor" opacity="0.1" />
                        <text x="{{ $plotLeft - 8 }}" y="{{ $yValue($value) + 4 }}" text-anchor="end" fill="currentColor" font-size="11">{{ $formatValue($value) }}</text>
                    @endif
                @endfor
                @foreach ($series as $s => $name)
                    @php $path = ''; $connected = false; @endphp
                    @foreach ($points as $i => $point)
                        @php
                            $value = $point['values'][$s];
                            $color = $colors[$s % count($colors)];
                            $x = $plotLeft + ($i + 0.5) * $step;
                            if ($value === null) { $connected = false; continue; }
                            $y = $yValue($value);
                            $path .= ($connected ? ' L ' : ' M ').$x.' '.$y;
                            $connected = true;
                        @endphp
                        @if ($chartType === 'line')
                            <circle cx="{{ $x }}" cy="{{ $y }}" r="3.5" fill="{{ $color }}"><title>{{ $point['label'] }} · {{ $name }}: {{ $formatValue($value) }}</title></circle>
                            @if ($showValues)<text x="{{ $x }}" y="{{ $y - 8 }}" text-anchor="middle" fill="{{ $color }}" font-size="10">{{ $formatValue($value) }}</text>@endif
                        @elseif ($isHorizontal)
                            @php $barY = $plotTop + $i * $groupHeight + $s * 18; @endphp
                            <rect x="{{ min($xValue(0), $xValue($value)) }}" y="{{ $barY }}" width="{{ abs($xValue($value) - $xValue(0)) }}" height="14" rx="2" fill="{{ $color }}"><title>{{ $point['label'] }} · {{ $name }}: {{ $formatValue($value) }}</title></rect>
                            @if ($showValues)<text x="{{ $xValue($value) + 5 }}" y="{{ $barY + 11 }}" fill="currentColor" font-size="10">{{ $formatValue($value) }}</text>@endif
                        @else
                            @php $barWidth = min(45, $step * 0.75 / $seriesCount); $barX = $x - $barWidth * $seriesCount / 2 + $s * $barWidth; @endphp
                            <rect x="{{ $barX }}" y="{{ min($yValue(0), $y) }}" width="{{ max(1, $barWidth - 2) }}" height="{{ abs($yValue(0) - $y) }}" rx="2" fill="{{ $color }}"><title>{{ $point['label'] }} · {{ $name }}: {{ $formatValue($value) }}</title></rect>
                            @if ($showValues)<text x="{{ $barX + $barWidth / 2 }}" y="{{ $y - 5 }}" text-anchor="middle" fill="currentColor" font-size="10">{{ $formatValue($value) }}</text>@endif
                        @endif
                    @endforeach
                    @if ($chartType === 'line')<path d="{{ $path }}" fill="none" stroke="{{ $colors[$s % count($colors)] }}" stroke-width="2" />@endif
                @endforeach
                @foreach ($points as $i => $point)
                    @if ($isHorizontal)
                        <text x="{{ $plotLeft - 10 }}" y="{{ $plotTop + $i * $groupHeight + 12 }}" text-anchor="end" fill="currentColor" font-size="11"><title>{{ $point['label'] }}</title>{{ \Illuminate\Support\Str::limit($point['label'], 28) }}</text>
                    @else
                        <text transform="translate({{ $plotLeft + ($i + 0.5) * $step }}, {{ $plotBottom + 16 }}) rotate(25)" fill="currentColor" font-size="10"><title>{{ $point['label'] }}</title>{{ \Illuminate\Support\Str::limit($point['label'], 24) }}</text>
                    @endif
                @endforeach
            </svg>
        </div>
        @if ($truncated || $seriesTruncated)<p class="mt-1 text-[11px] text-warning">{{ $truncated ? 'Category limit reached. Increase the limit or narrow your filters. ' : '' }}{{ $seriesTruncated ? 'Showing the first '.$seriesLimit.' series. Narrow your filters to see more.' : '' }}</p>@endif
        <details class="mt-2 text-xs text-base-content/60">
            <summary class="cursor-pointer">View chart data</summary>
            <div class="max-h-48 overflow-auto"><table class="mt-2 w-full text-left"><thead><tr><th class="p-1">Category</th>@foreach ($series as $name)<th class="p-1">{{ $name }}</th>@endforeach</tr></thead><tbody>
                @foreach ($points as $point)<tr><th class="p-1 font-normal">{{ $point['label'] }}</th>@foreach ($point['values'] as $value)<td class="p-1">{{ $value === null ? '—' : $formatValue($value) }}</td>@endforeach</tr>@endforeach
            </tbody></table></div>
        </details>
    @endif
</div>
