@php
    $toneFill = ['primary' => 'bg-primary', 'success' => 'bg-success', 'info' => 'bg-info', 'warning' => 'bg-warning', 'error' => 'bg-error', 'neutral' => 'bg-base-300'];
    $ragBg = ['green' => 'bg-success', 'amber' => 'bg-warning', 'red' => 'bg-error'];
    $ragText = ['green' => 'text-success', 'amber' => 'text-warning', 'red' => 'text-error'];
    $ragGlyph = ['green' => '✓', 'amber' => '!', 'red' => '✕'];
    $ragLabel = ['green' => 'on track', 'amber' => 'watch', 'red' => 'critical'];
    $chartColors = [
        'primary' => 'var(--color-primary)',
        'success' => 'var(--color-success)',
        'warning' => 'var(--color-warning)',
        'info' => 'var(--color-info)',
        'error' => 'var(--color-error)',
        'secondary' => 'var(--color-secondary)',
        'accent' => 'var(--color-accent)',
        'neutral' => 'var(--color-base-300)',
    ];

    // Brand donut tones (cycled), Others neutral.
    $brandTones = ['primary', 'success', 'warning', 'info', 'error', 'secondary'];
    $brandSegments = collect($brandDonut)->map(function ($segment, $i) use ($brandTones) {
        $segment['tone'] = $segment['tone'] ?? $brandTones[$i % count($brandTones)];

        return $segment;
    })->all();

    $headlineKeys = ['Technical reports', 'Completed', 'Assigned TSP', 'Avg repair time'];
    $primary = collect($kpis)->filter(fn ($k) => in_array($k['label'], $headlineKeys))->values()->all();
    $secondaryKpis = collect($kpis)->filter(fn ($k) => ! in_array($k['label'], $headlineKeys))->values()->all();
    $lead = $primary[0] ?? null;
    $rest = array_slice($primary, 1);
@endphp

<div class="mx-auto w-full max-w-none space-y-8 pb-4">
    <header class="border-b border-base-300 pb-6 pt-2">
        <div class="flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
            <div class="min-w-0">
                <p class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.18em] text-primary">
                    <span class="inline-block h-px w-6 bg-primary/60"></span>
                    Technical Reports
                </p>
                <h1 class="font-display text-3xl font-semibold tracking-tight text-base-content sm:text-4xl">Technical Service Analysis</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-base-content/55">Completion, TSP workload, and equipment patterns — computed live from the imported Technical Reports table.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" onclick="window.print()" class="admin-secondary-button no-print">Print report</button>
                <a href="{{ route('technical-reports') }}" wire:navigate class="admin-primary-button no-print">Open Technical Reports</a>
            </div>
        </div>
        <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-base-300 pt-4">
            <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] text-base-content/50">Window</div>
            <select wire:model.live="period" class="admin-control w-auto min-w-[130px]" aria-label="Reporting period">
                @foreach ($periodOptions as $option)
                    <option value="{{ $option }}">Last {{ $option }}</option>
                @endforeach
            </select>
            <div class="ml-auto flex items-center gap-2 text-xs text-base-content/45">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success/50 opacity-60"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-success"></span>
                </span>
                <span>Report data as of <span class="font-semibold text-base-content/70">{{ $freshness }}</span></span>
            </div>
        </div>
    </header>

    <section aria-label="Headline metrics" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        @if ($lead)
        <div class="admin-surface relative overflow-hidden p-6 transition duration-200 hover:border-primary/40 sm:p-8 xl:col-span-1">
            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-primary/[0.07] blur-2xl"></div>
            <div class="relative">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-base-content/50">{{ $lead['label'] }}</p>
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full {{ $ragBg[$lead['rag']] }}/15 text-[10px] font-black {{ $ragText[$lead['rag']] }}" title="{{ $ragLabel[$lead['rag']] }}">{{ $ragGlyph[$lead['rag']] }}</span>
                </div>
                <p class="mt-5 font-display text-6xl font-semibold leading-none tracking-tight tabular-nums text-base-content sm:text-7xl">{{ number_format($lead['value'], $lead['decimals'] ?? 0) }}</p>
                <p class="mt-2 text-xs uppercase tracking-[0.1em] text-base-content/40">reports on file</p>
                <div class="mt-6 flex flex-wrap gap-2">
                    <span class="rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">{{ $lead['context'] }}</span>
                    @if ($lead['href'] ?? null)
                        <a href="{{ $lead['href'] }}" wire:navigate class="inline-flex items-center gap-1 text-[11px] font-semibold text-base-content/50 underline-offset-2 hover:text-primary hover:underline">Open table <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5" /></a>
                    @endif
                </div>
            </div>
        </div>
        @endif
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:col-span-2">
            @foreach ($rest as $kpi)
                <a href="{{ $kpi['href'] }}" wire:navigate
                     class="admin-surface group flex cursor-pointer flex-col justify-between p-6 transition duration-200 hover:-translate-y-1 hover:bg-base-200/40 hover:shadow-lg hover:shadow-primary/10">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 truncate text-[11px] font-bold uppercase tracking-[0.14em] text-base-content/45">{{ $kpi['label'] }}</p>
                        <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full {{ $ragBg[$kpi['rag']] }} text-[9px] font-black text-base-100" title="{{ $ragLabel[$kpi['rag']] }}">{{ $ragGlyph[$kpi['rag']] }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-baseline gap-x-2">
                        <span class="font-display text-4xl font-semibold leading-none tracking-tight tabular-nums">{{ number_format($kpi['value'], $kpi['decimals'] ?? 0) }}{{ $kpi['suffix'] ?? '' }}</span>
                    </div>
                    <div class="mt-3 flex items-end justify-between gap-2">
                        <p class="truncate text-[11px] text-base-content/40">{{ $kpi['context'] }}</p>
                        <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5 shrink-0 text-primary opacity-0 transition-opacity duration-200 group-hover:opacity-100" />
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    <section aria-label="Supporting metrics" class="admin-surface grid grid-cols-1 divide-y divide-base-300 sm:grid-cols-3 sm:divide-y-0 sm:divide-x">
        @foreach ($secondary as $kpi)
            <a href="{{ $kpi['href'] }}" wire:navigate class="group flex items-center gap-4 px-6 py-4 transition duration-200 hover:bg-base-200/40">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $ragBg[$kpi['rag']] }}/15 text-sm font-black {{ $ragText[$kpi['rag']] }}" aria-hidden="true">{{ $ragGlyph[$kpi['rag']] }}</div>
                <div class="min-w-0">
                    <p class="truncate text-[11px] font-bold uppercase tracking-[0.12em] text-base-content/45">{{ $kpi['label'] }}</p>
                    <p class="mt-1 font-display text-xl font-semibold leading-none tabular-nums">{{ number_format($kpi['value'], $kpi['decimals'] ?? 0) }}{{ $kpi['suffix'] ?? '' }}</p>
                    <p class="mt-1 truncate text-[10px] text-base-content/35">{{ $kpi['context'] }}</p>
                </div>
                <x-mary-icon name="o-arrow-right" class="ml-auto h-3.5 w-3.5 shrink-0 text-primary opacity-0 transition-opacity duration-200 group-hover:opacity-100" />
            </a>
        @endforeach
    </section>

    <section aria-label="Status mix and completion trend" class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.35fr)]">
        <div class="admin-surface flex h-full flex-col p-6 sm:p-7" x-data="{ active: null }">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">01 · Status mix</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Report status</h2>
            <p class="mt-1 text-xs text-base-content/50">Hover to focus · click a segment or row to open the reports</p>
            <div class="mt-7 flex flex-1 flex-col items-center justify-center gap-7">
                <div class="relative h-40 w-40 shrink-0">
                    <svg viewBox="0 0 42 42" class="h-40 w-40 -rotate-90" role="img" aria-label="Report status mix">
                        @php $offset = 0; $circ = 2 * pi() * 15.9155; @endphp
                        @foreach ($statusDonut as $i => $seg)
                            @php
                                $frac = $statusTotal > 0 ? $seg['value'] / $statusTotal : 0;
                                $len = $frac * $circ;
                            @endphp
                            @if ($seg['href'])
                                <a href="{{ $seg['href'] }}" wire:navigate x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null"
                                    class="cursor-pointer" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .3' : ''">
                                    <title>{{ $seg['label'] }} — view the {{ number_format($seg['value']) }} matching reports</title>
                                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $chartColors[$seg['tone']] }}" stroke-width="6"
                                        stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"
                                        style="transition: stroke-width .2s ease" :style="active === {{ $i }} ? 'stroke-width: 9' : ''"></circle>
                                </a>
                            @else
                                <g x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .3' : ''">
                                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $chartColors[$seg['tone']] }}" stroke-width="6"
                                        stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"></circle>
                                </g>
                            @endif
                            @php $offset += $len; @endphp
                        @endforeach
                        <circle cx="21" cy="21" r="12.9" fill="var(--color-base-100)" />
                    </svg>
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-display text-2xl font-semibold tabular-nums">{{ number_format($statusTotal) }}</span>
                        <span class="text-[9px] font-bold uppercase tracking-[0.14em] text-base-content/40">reports</span>
                    </div>
                </div>
                <ul class="w-full min-w-0 space-y-3">
                    @foreach ($statusDonut as $i => $seg)
                        <li x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .45' : ''">
                            <a @if ($seg['href']) href="{{ $seg['href'] }}" wire:navigate @endif class="flex items-center gap-3 text-sm {{ $seg['href'] ? 'cursor-pointer transition-colors hover:text-primary' : '' }}" @if ($seg['href']) title="View the {{ number_format($seg['value']) }} {{ strtolower($seg['label']) }} reports" @endif>
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $toneFill[$seg['tone']] }} transition-transform duration-200" :style="active === {{ $i }} ? 'transform: scale(1.5)' : ''"></span>
                                <span class="min-w-0 flex-1 truncate text-base-content/65">{{ $seg['label'] }}</span>
                                <span class="font-semibold tabular-nums">{{ number_format($seg['value']) }}</span>
                                <span class="w-12 text-right text-[11px] tabular-nums text-base-content/35">{{ round(($seg['value'] / $statusTotal) * 100) }}%</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="admin-surface flex flex-col p-6 sm:p-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">02 · Completion trend</p>
                    <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Reports completed</h2>
                    <p class="mt-1 text-xs text-base-content/50">By completion date · last {{ $trendDays }} days — click a bar to open those reports</p>
                </div>
                <span class="inline-flex shrink-0 items-center gap-2 self-start rounded-full bg-primary/10 px-3 py-1.5 text-xs font-bold text-primary">
                    <span class="text-[10px] opacity-70">total</span>
                    {{ number_format($trendTotal) }}
                </span>
            </div>
            @if ($trendTotal > 0)
                <div class="mt-7 flex flex-1 items-end gap-2 border-b border-l border-base-300 px-4 pb-0 pt-4 sm:gap-3">
                    @foreach ($trend as $point)
                        <a href="{{ $point['href'] }}" wire:navigate class="group flex h-full flex-1 cursor-pointer flex-col items-center justify-end gap-2" title="{{ $point['label'] }} · {{ number_format($point['count']) }} reports completed — click to view">
                            <span class="tabular-nums text-[10px] font-bold text-base-content/45 transition-colors group-hover:text-primary">{{ $point['count'] }}</span>
                            <div class="w-full max-w-12 rounded-t-sm bg-primary/75 transition-all duration-200 group-hover:bg-primary group-hover:shadow-md group-hover:shadow-primary/30" style="height: {{ max(4, round(($point['count'] / $trendMax) * 100)) }}%"></div>
                            <span class="text-[10px] font-bold text-base-content/45 transition-colors group-hover:text-primary">{{ $point['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="mt-6 flex flex-1 items-center justify-center">
                    <div class="w-full rounded-lg border border-dashed border-base-300 p-6 text-center">
                        <p class="text-sm font-semibold text-base-content/70">No reports completed in the last {{ $trendDays }} days</p>
                        <p class="mt-1 text-xs text-base-content/45">Completion dates in the imported data are older than this window.</p>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <section aria-label="Workload and brand mix" class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
        <div class="admin-surface flex h-full flex-col p-6 sm:p-7">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">03 · Engineer workload</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Top TSPs by reports</h2>
            <p class="mt-1 text-xs text-base-content/50">Click a row to open that engineer's reports</p>
            <div class="mt-6 space-y-3">
                @forelse ($byTsp as $i => $row)
                    <a href="{{ $row['href'] }}" wire:navigate class="group flex items-center gap-3 rounded-md transition duration-200 hover:bg-base-200/50" title="View the {{ number_format($row['total']) }} reports by {{ $row['label'] }}">
                        <span class="w-5 shrink-0 text-right text-[11px] font-black tabular-nums text-base-content/30">{{ $i + 1 }}</span>
                        <span class="w-44 shrink-0 truncate text-sm font-medium text-base-content/80" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                        <div class="h-6 flex-1 overflow-hidden rounded-full bg-base-200/80">
                            <div class="h-full rounded-full bg-gradient-to-r from-primary to-primary/60 transition-all duration-200 group-hover:to-primary" style="width: {{ round(($row['total'] / $tspMax) * 100) }}%"></div>
                        </div>
                        <span class="w-10 shrink-0 text-right text-xs font-bold tabular-nums text-base-content/70">{{ number_format($row['total']) }}</span>
                        <x-mary-icon name="o-chevron-right" class="h-3.5 w-3.5 shrink-0 -translate-x-1 text-primary opacity-0 transition-all duration-200 group-hover:translate-x-0 group-hover:opacity-100" />
                    </a>
                @empty
                    <p class="text-sm text-base-content/45">No TSP workload data available.</p>
                @endforelse
            </div>
        </div>

        <div class="admin-surface flex h-full flex-col p-6 sm:p-7" x-data="{ active: null }">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">04 · Equipment serviced</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Most serviced brands</h2>
            <p class="mt-1 text-xs text-base-content/50">Hover to focus · click a segment or row to open the reports</p>
            <div class="my-auto mt-7 flex flex-col items-center gap-8 lg:flex-row lg:items-center">
                <div class="relative h-40 w-40 shrink-0">
                    <svg viewBox="0 0 42 42" class="h-40 w-40 -rotate-90" role="img" aria-label="Most serviced brands">
                        @php $offset = 0; $circ = 2 * pi() * 15.9155; @endphp
                        @foreach ($brandSegments as $i => $seg)
                            @php $len = ($seg['value'] / $brandTotal) * $circ; @endphp
                            @if ($seg['href'])
                                <a href="{{ $seg['href'] }}" wire:navigate x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null"
                                    class="cursor-pointer" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .3' : ''">
                                    <title>{{ $seg['label'] }} — view the {{ number_format($seg['value']) }} {{ $seg['label'] }} reports</title>
                                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $seg['color'] }}" stroke-width="6"
                                        stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"
                                        style="transition: stroke-width .2s ease" :style="active === {{ $i }} ? 'stroke-width: 9' : ''"></circle>
                                </a>
                            @else
                                <g x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .3' : ''">
                                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $seg['color'] }}" stroke-width="6"
                                        stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"></circle>
                                </g>
                            @endif
                            @php $offset += $len; @endphp
                        @endforeach
                        <circle cx="21" cy="21" r="12.9" fill="var(--color-base-100)" />
                    </svg>
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-display text-2xl font-semibold tabular-nums">{{ number_format($brandTotal) }}</span>
                        <span class="text-[9px] font-bold uppercase tracking-[0.14em] text-base-content/40">reports</span>
                    </div>
                </div>
                <ul class="w-full min-w-0 flex-1 space-y-2.5">
                    @forelse ($brandSegments as $i => $seg)
                        <li x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .45' : ''">
                            <a @if ($seg['href']) href="{{ $seg['href'] }}" wire:navigate @endif class="flex items-center gap-2.5 text-sm {{ $seg['href'] ? 'cursor-pointer transition-colors hover:text-primary' : '' }}" @if ($seg['href']) title="View the {{ number_format($seg['value']) }} {{ $seg['label'] }} reports" @endif>
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full transition-transform duration-200" style="background: {{ $seg['color'] }}" :style="active === {{ $i }} ? 'transform: scale(1.5)' : ''"></span>
                                <span class="min-w-0 flex-1 truncate text-base-content/65">{{ $seg['label'] }}</span>
                                <span class="font-semibold tabular-nums">{{ number_format($seg['value']) }}</span>
                                <span class="w-11 text-right text-[11px] tabular-nums text-base-content/35">{{ round(($seg['value'] / $brandTotal) * 100) }}%</span>
                            </a>
                        </li>
                    @empty
                        <li class="text-sm text-base-content/45">No brand data available.</li>
                    @endforelse
                </ul>
            </div>
            <p class="mt-4 border-t border-base-300 pt-4 text-xs text-base-content/45">Average response time: {{ number_format($avgResponse, 1) }}h</p>
        </div>
    </section>

    <footer class="flex flex-col gap-2 px-1 text-[11px] text-base-content/35 sm:flex-row sm:items-center sm:justify-between">
        <span>Technical Reports overview · click any card, segment, or bar to open the reports behind it — the grid arrives pre-filtered, with a one-click clear.</span>
        <span>All metrics computed live from the imported Technical Reports table — never hardcoded.</span>
    </footer>
</div>
