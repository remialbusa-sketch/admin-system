@php
    $toneText = ['primary' => 'text-primary', 'success' => 'text-success', 'info' => 'text-info', 'warning' => 'text-warning', 'error' => 'text-error', 'neutral' => 'text-base-content/70'];
    $toneFill = ['primary' => 'bg-primary', 'success' => 'bg-success', 'info' => 'bg-info', 'warning' => 'bg-warning', 'error' => 'bg-error', 'neutral' => 'bg-base-300'];
    $ragBg = ['green' => 'bg-success', 'amber' => 'bg-warning', 'red' => 'bg-error'];
    $ragText = ['green' => 'text-success', 'amber' => 'text-warning', 'red' => 'text-error'];
    $ragGlyph = ['green' => '✓', 'amber' => '!', 'red' => '✕'];
    $ragLabel = ['green' => 'on track', 'amber' => 'watch', 'red' => 'critical'];
@endphp

<div class="mx-auto w-full max-w-none space-y-10 pb-4">
    <header class="border-b border-base-300 pb-8 pt-2">
        <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <div class="min-w-0">
                <p class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.18em] text-primary">
                    <span class="inline-block h-px w-6 bg-primary/60"></span>
                    Executive reporting
                </p>
                <h1 class="font-display text-3xl font-semibold tracking-tight text-base-content sm:text-4xl">President Overview</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-base-content/55">A command view over the installed base, service operations, and isolated historical records — computed live from the imported source tables.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <a href="{{ route('installed-products') }}" wire:navigate class="admin-secondary-button">Product Database <x-mary-icon name="o-arrow-right" class="h-4 w-4" /></a>
                <a href="{{ route('tsp-analytics') }}" wire:navigate class="admin-primary-button">Personnel Analytics</a>
            </div>
        </div>
        <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-base-300 pt-5">
            <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] text-base-content/50">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/></svg>
                Scope
            </div>
            <select wire:model.live="region" class="admin-control min-w-[170px]" aria-label="Filter by region">
                @foreach ($regionOptions as $option)
                    <option>{{ $option }}</option>
                @endforeach
            </select>
            <div class="ml-auto flex items-center gap-2 text-xs text-base-content/45">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success/50 opacity-60"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-success"></span>
                </span>
                <span>Data as of <span class="font-semibold text-base-content/70">{{ $freshness }}</span></span>
            </div>
        </div>
    </header>

    @php
        $headlineKeys = ['Product database', 'Active products', 'Service requests', 'Completion rate', 'Avg repair time'];
        $primary = collect($kpis)->filter(fn ($k) => in_array($k['label'], $headlineKeys))->values()->all();
        $secondary = collect($kpis)->filter(fn ($k) => ! in_array($k['label'], $headlineKeys))->values()->all();
        $lead = $primary[0] ?? null;
        $rest = array_slice($primary, 1);
    @endphp
    <section aria-label="Headline metrics" class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        @if ($lead)
        <div class="admin-surface relative overflow-hidden p-7 sm:p-9 xl:col-span-1">
            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-primary/[0.07] blur-2xl"></div>
            <div class="relative">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-base-content/50">{{ $lead['label'] }}</p>
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-success/15 text-[10px] font-black text-success" title="{{ $ragLabel[$lead['rag']] }}">{{ $ragGlyph[$lead['rag']] }}</span>
                </div>
                <p class="mt-6 font-display text-6xl font-semibold leading-none tracking-tight tabular-nums text-base-content sm:text-7xl">{{ number_format($lead['value']) }}</p>
                <p class="mt-3 text-xs uppercase tracking-[0.1em] text-base-content/40">units installed</p>
                <div class="mt-8 flex flex-wrap gap-2">
                    <span class="rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">{{ $lead['context'] }}</span>
                    <a href="{{ $lead['href'] ?? '#' }}" wire:navigate class="inline-flex items-center gap-1 text-[11px] font-semibold text-base-content/50 underline-offset-2 hover:text-primary hover:underline">Open table <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5" /></a>
                </div>
            </div>
        </div>
        @endif
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:col-span-2">
            @foreach ($rest as $kpi)
                <div @if (!empty($kpi['href'])) onclick="location.href='{{ $kpi['href'] }}'" @endif
                     class="admin-surface flex flex-col justify-between p-6 {{ !empty($kpi['href']) ? 'cursor-pointer transition hover:bg-base-200/40' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 truncate text-[11px] font-bold uppercase tracking-[0.14em] text-base-content/45">{{ $kpi['label'] }}</p>
                        <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full {{ $ragBg[$kpi['rag']] }} text-[9px] font-black text-base-100" title="{{ $ragLabel[$kpi['rag']] }}">{{ $ragGlyph[$kpi['rag']] }}</span>
                    </div>
                    <div class="mt-4 flex flex-wrap items-baseline gap-x-2">
                        <span class="font-display text-4xl font-semibold leading-none tracking-tight tabular-nums">{{ number_format($kpi['value']) }}{{ $kpi['suffix'] ?? '' }}</span>
                        @if (isset($kpi['trend']))
                            <span class="text-xs font-bold {{ $kpi['trend'] >= 0 ? 'text-success' : 'text-error' }}">{{ $kpi['trend'] >= 0 ? '▲' : '▼' }} {{ abs($kpi['trend']) }}%</span>
                        @endif
                    </div>
                    <div class="mt-3 flex items-end justify-between gap-2">
                        <p class="truncate text-[11px] text-base-content/40">{{ $kpi['context'] }}</p>
                        @if (!empty($kpi['spark']))
                            @php
                                $spark = $kpi['spark'];
                                $max = max(1, max(...$spark));
                                $min = min($spark);
                                $n = count($spark);
                                $pts = implode(' ', array_map(fn ($i) => round(($i / max(1, $n - 1)) * 100, 1).','.round(22 - (($spark[$i] - $min) / max(1, $max - $min)) * 18, 1), array_keys($spark)));
                            @endphp
                            <svg viewBox="0 0 100 22" preserveAspectRatio="none" class="h-6 w-24 shrink-0"><polyline points="{{ $pts }}" fill="none" stroke="{{ $kpi['trend'] >= 0 ? 'oklch(73% 0.11 155)' : 'oklch(72% 0.14 25)' }}" stroke-width="2" vector-effect="non-scaling-stroke" /></svg>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section aria-label="Supporting metrics" class="admin-surface grid grid-cols-1 divide-y divide-base-300 sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x">
        @foreach ($secondary as $kpi)
            <div @if (!empty($kpi['href'])) onclick="location.href='{{ $kpi['href'] }}'" @endif
                 class="group flex items-center gap-4 px-6 py-5 {{ !empty($kpi['href']) ? 'cursor-pointer transition hover:bg-base-200/50' : '' }}">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $ragBg[$kpi['rag']] }}/15 text-sm font-black {{ $ragText[$kpi['rag']] }}" aria-hidden="true">{{ $ragGlyph[$kpi['rag']] }}</div>
                <div class="min-w-0">
                    <p class="truncate text-[11px] font-bold uppercase tracking-[0.12em] text-base-content/45">{{ $kpi['label'] }}</p>
                    <p class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-display text-xl font-semibold leading-none tabular-nums">{{ number_format($kpi['value']) }}{{ $kpi['suffix'] ?? '' }}</span>
                        <span class="truncate text-[10px] text-base-content/35">{{ $kpi['context'] }}</span>
                    </p>
                </div>
            </div>
        @endforeach
    </section>

    <section aria-label="Service performance" class="grid grid-cols-1 gap-6 xl:grid-cols-5">
        <div class="admin-surface p-7 sm:p-8 xl:col-span-3">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">01 · Intake trend</p>
                    <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Service request intake</h2>
                    <p class="mt-1 text-xs text-base-content/50">New requests by month · last 12 months</p>
                </div>
                <span class="inline-flex shrink-0 items-center gap-2 self-start rounded-full px-3 py-1.5 text-xs font-bold {{ $requestMoM >= 0 ? 'bg-success/10 text-success' : 'bg-error/10 text-error' }}">
                    <span class="text-[10px] opacity-70">MoM</span>
                    {{ $requestMoM >= 0 ? '▲' : '▼' }} {{ abs($requestMoM) }}%
                </span>
            </div>
            <div class="mt-8">
                <svg viewBox="0 0 640 140" class="h-44 w-full lg:h-52" role="img" aria-label="Service request intake trend">
                    <defs>
                        <linearGradient id="areaFade" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="oklch(70% 0.14 242)" stop-opacity="0.18" />
                            <stop offset="100%" stop-color="oklch(70% 0.14 242)" stop-opacity="0" />
                        </linearGradient>
                    </defs>
                    <path d="{{ $requestArea['area'] }}" fill="url(#areaFade)" />
                    <path d="{{ $requestArea['line'] }}" fill="none" stroke="oklch(70% 0.14 242)" stroke-width="2.5" vector-effect="non-scaling-stroke" />
                    @foreach ($requestArea['dots'] as $dot)
                        <circle cx="{{ $dot[0] }}" cy="{{ $dot[1] }}" r="3" fill="oklch(70% 0.14 242)" />
                    @endforeach
                </svg>
                <div class="mt-3 flex justify-between text-[10px] font-semibold uppercase tracking-wide text-base-content/35">
                    <span>{{ $requestTrend[0]['short'] }}</span>
                    <span>{{ $requestTrend[intdiv(count($requestTrend), 2)]['short'] }}</span>
                    <span>{{ $requestTrend[count($requestTrend) - 1]['short'] }}</span>
                </div>
            </div>
        </div>

        <div class="admin-surface p-7 sm:p-8 xl:col-span-2">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">02 · Status mix</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Outcome share</h2>
            <p class="mt-1 text-xs text-base-content/50">Requests by resolution state</p>
            <div class="mt-8 flex flex-col items-center gap-8 lg:flex-row">
                <div class="relative h-36 w-36 shrink-0">
                    <svg viewBox="0 0 36 36" class="h-36 w-36 -rotate-90">
                        @php $offset = 0; $circ = 2 * pi() * 15.9155; @endphp
                        @foreach ($statusDonut as $seg)
                            @php
                                $frac = $statusTotal > 0 ? $seg['value'] / $statusTotal : 0;
                                $len = $frac * $circ;
                                $stroke = match ($seg['tone']) {
                                    'success' => 'oklch(73% 0.11 155)',
                                    'warning' => 'oklch(80% 0.12 78)',
                                    'error' => 'oklch(72% 0.14 25)',
                                    default => 'oklch(31% 0.03 255)',
                                };
                            @endphp
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="{{ $stroke }}" stroke-width="6"
                                stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"></circle>
                            @php $offset += $len; @endphp
                        @endforeach
                        <circle cx="18" cy="18" r="10.5" fill="oklch(22% 0.025 255)" />
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-display text-2xl font-semibold tabular-nums">{{ $completionRate }}%</span>
                        <span class="text-[9px] font-bold uppercase tracking-[0.14em] text-base-content/40">done</span>
                    </div>
                </div>
                <ul class="w-full flex-1 space-y-3">
                    @foreach ($statusDonut as $seg)
                        <li class="flex items-center gap-3 text-sm">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $toneFill[$seg['tone']] }}"></span>
                            <span class="flex-1 text-base-content/65">{{ $seg['label'] }}</span>
                            <span class="font-semibold tabular-nums">{{ number_format($seg['value']) }}</span>
                            <span class="w-12 text-right text-[11px] tabular-nums text-base-content/35">{{ round(($seg['value'] / $statusTotal) * 100) }}%</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    <section aria-label="Attention required" class="admin-surface p-7 sm:p-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">03 · Attention</p>
                <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">What needs action</h2>
                <p class="mt-1 text-xs text-base-content/50">Regions ranked by open service requests · red critical, amber watch</p>
            </div>
            <a href="{{ route('service-requests') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">Open requests <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5" /></a>
        </div>
        <div class="mt-7 overflow-hidden rounded-xl border border-base-300">
            <div class="grid grid-cols-12 gap-3 border-b border-base-300 bg-base-200/50 px-5 py-2.5 text-[10px] font-bold uppercase tracking-[0.12em] text-base-content/45">
                <span class="col-span-5 sm:col-span-4">Region</span>
                <span class="col-span-3 sm:col-span-3">Open</span>
                <span class="col-span-2 hidden sm:block">Ratio</span>
                <span class="col-span-4 sm:col-span-3 text-right">Status</span>
            </div>
            @foreach ($attention as $row)
                <div class="grid grid-cols-12 items-center gap-3 border-b border-base-300/70 px-5 py-4 last:border-b-0">
                    <div class="col-span-5 flex items-center gap-3 sm:col-span-4">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $ragBg[$row['rag']] }} text-xs font-black text-base-100" aria-hidden="true">{{ $ragGlyph[$row['rag']] }}</span>
                        <span class="truncate text-sm font-semibold text-base-content">{{ $row['region'] }}</span>
                    </div>
                    <div class="col-span-3 font-display text-lg font-semibold tabular-nums sm:col-span-3">{{ number_format($row['open']) }}</div>
                    <div class="col-span-2 hidden sm:block">
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-base-200">
                            <div class="h-1.5 rounded-full {{ $ragBg[$row['rag']] }}" style="width: {{ min(100, $row['ratio']) }}%"></div>
                        </div>
                    </div>
                    <div class="col-span-4 text-right sm:col-span-3">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold {{ $ragBg[$row['rag']] }}/12 {{ $ragText[$row['rag']] }}">
                            {{ $ragLabel[$row['rag']] }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section aria-label="Regional scale" class="admin-surface p-7 sm:p-8">
        <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">04 · Scale</p>
        <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Regional operating position</h2>
        <p class="mt-1 text-xs text-base-content/50">Installed products, deployed personnel, and open workload per region</p>
        <div class="mt-8 space-y-7">
            @foreach ($regions as $row)
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-center lg:gap-6">
                    <div class="lg:col-span-2">
                        <p class="font-display text-lg font-semibold text-base-content">{{ $row['region'] }}</p>
                        @if ($row['attention'])
                            <p class="mt-0.5 text-[10px] font-bold uppercase tracking-wide text-error">High open load</p>
                        @endif
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:col-span-10">
                        @foreach ([
                            ['label' => 'Products', 'value' => $row['products'], 'max' => $regionMax['products'], 'tone' => 'primary'],
                            ['label' => 'Personnel', 'value' => $row['personnel'], 'max' => $regionMax['personnel'], 'tone' => 'info'],
                            ['label' => 'Open', 'value' => $row['open_requests'], 'max' => $regionMax['open_requests'], 'tone' => $row['attention'] ? 'error' : 'warning'],
                        ] as $cell)
                            <div class="min-w-0">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="text-[11px] font-bold uppercase tracking-[0.1em] text-base-content/40">{{ $cell['label'] }}</span>
                                    <span class="font-display text-lg font-semibold tabular-nums">{{ number_format($cell['value']) }}</span>
                                </div>
                                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-base-200">
                                    <div class="h-1.5 rounded-full {{ $toneFill[$cell['tone']] }}" style="width: {{ round(($cell['value'] / $cell['max']) * 100) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section aria-label="Distribution" class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <div class="admin-surface p-7 sm:p-8">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">05 · Base composition</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Leading installed brands</h2>
            <p class="mt-1 text-xs text-base-content/50">Top equipment brands by record count</p>
            <div class="mt-7 space-y-4">
                @php $brandMaxP = max(1, (int) collect($productsByBrand)->max('total')); @endphp
                @forelse ($productsByBrand as $row)
                    <div class="flex items-center gap-4">
                        <span class="w-28 shrink-0 truncate text-sm font-medium text-base-content/80">{{ $row['label'] }}</span>
                        <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-base-200">
                            <div class="h-2.5 rounded-full bg-primary/80" style="width: {{ round(($row['total'] / $brandMaxP) * 100) }}%"></div>
                        </div>
                        <span class="w-12 text-right text-xs font-semibold tabular-nums text-base-content/60">{{ number_format($row['total']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/45">No Product Database data imported.</p>
                @endforelse
            </div>
        </div>

        <div class="admin-surface p-7 sm:p-8">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">06 · Engineer workload</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Top TSPs by work logged</h2>
            <p class="mt-1 text-xs text-base-content/50">Technical reports per field engineer</p>
            <div class="mt-7 space-y-4">
                @php $tspMax = max(1, (int) collect($topTsps)->max('total')); @endphp
                @forelse ($topTsps as $row)
                    <div class="flex items-center gap-4">
                        <span class="w-40 shrink-0 truncate text-sm font-medium text-base-content/80" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                        <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-base-200">
                            <div class="h-2.5 rounded-full bg-warning/80" style="width: {{ round(($row['total'] / $tspMax) * 100) }}%"></div>
                        </div>
                        <span class="w-12 text-right text-xs font-semibold tabular-nums text-base-content/60">{{ number_format($row['total']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/45">No TSP workload data available.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section aria-label="Operations detail" class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="admin-surface p-7 sm:p-8">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">07 · By branch</p>
            <h2 class="mt-2 font-display text-lg font-semibold tracking-tight">Service requests</h2>
            <div class="mt-6 space-y-3.5">
                @php $branchMax = max(1, (int) collect($byBranch)->max('total')); @endphp
                @forelse ($byBranch as $row)
                    <div class="flex items-center gap-3">
                        <span class="w-24 shrink-0 truncate text-sm text-base-content/70">{{ $row['label'] }}</span>
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-base-200"><div class="h-2 rounded-full bg-info/80" style="width: {{ round(($row['total'] / $branchMax) * 100) }}%"></div></div>
                        <span class="w-10 text-right text-xs font-semibold tabular-nums">{{ number_format($row['total']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/45">No branch data available.</p>
                @endforelse
            </div>
        </div>

        <div class="admin-surface p-7 sm:p-8">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">08 · Request types</p>
            <h2 class="mt-2 font-display text-lg font-semibold tracking-tight">Workload by type</h2>
            <div class="mt-6 space-y-3.5">
                @php $typeMax = max(1, (int) collect($topTypes)->max('total')); @endphp
                @forelse ($topTypes as $row)
                    <div class="flex items-center gap-3">
                        <span class="w-28 shrink-0 truncate text-sm text-base-content/70">{{ $row['label'] }}</span>
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-base-200"><div class="h-2 rounded-full bg-primary/80" style="width: {{ round(($row['total'] / $typeMax) * 100) }}%"></div></div>
                        <span class="w-10 text-right text-xs font-semibold tabular-nums">{{ number_format($row['total']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/45">No service type data available.</p>
                @endforelse
            </div>
        </div>

        <div class="admin-surface p-7 sm:p-8">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">09 · Serviced brands</p>
            <h2 class="mt-2 font-display text-lg font-semibold tracking-tight">Equipment most serviced</h2>
            <div class="mt-6 space-y-3.5">
                @php $brandMaxS = max(1, (int) collect($topBrands)->max('total')); @endphp
                @forelse ($topBrands as $row)
                    <div class="flex items-center gap-3">
                        <span class="w-28 shrink-0 truncate text-sm text-base-content/70">{{ $row['label'] }}</span>
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-base-200"><div class="h-2 rounded-full bg-info/80" style="width: {{ round(($row['total'] / $brandMaxS) * 100) }}%"></div></div>
                        <span class="w-10 text-right text-xs font-semibold tabular-nums">{{ number_format($row['total']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/45">No brand data available.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section aria-label="Registers" class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <div class="admin-surface p-7 sm:p-8">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">10 · Personnel</p>
                    <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Technical personnel</h2>
                    <p class="mt-1 text-xs text-base-content/50">Service/field roles · showing {{ count($personnel) }}</p>
                </div>
                <a href="{{ route('personnel') }}" wire:navigate class="shrink-0 text-xs font-semibold text-primary hover:underline">View all →</a>
            </div>
            <div class="admin-scrollbar mt-6 hidden max-h-80 overflow-y-auto lg:block">
                <table class="data-table w-full text-left">
                    <thead><tr><th>Name</th><th>Position</th><th>Branch</th><th>Region</th></tr></thead>
                    <tbody>
                        @forelse ($personnel as $row)
                            <tr><td class="font-semibold">{{ $row['name'] }}</td><td>{{ $row['position'] ?: '—' }}</td><td>{{ $row['branch'] ?: '—' }}</td><td>{{ $row['region'] ?: '—' }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="text-center">No personnel records imported.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-6 space-y-3 lg:hidden">
                @forelse ($personnel as $row)
                    <div class="rounded-xl border border-base-300 bg-base-100 p-4">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold">{{ $row['name'] }}</span>
                            <span class="shrink-0 text-xs text-base-content/50">{{ $row['region'] ?: '—' }}</span>
                        </div>
                        <div class="mt-1 text-sm text-base-content/75">{{ $row['position'] ?: '—' }}</div>
                        <div class="text-xs text-base-content/45">{{ $row['branch'] ?: '—' }}</div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/45">No personnel records imported.</p>
                @endforelse
            </div>
        </div>

        <div class="admin-surface p-7 sm:p-8">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">11 · History</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Historical TSMS</h2>
            <p class="mt-1 text-xs text-base-content/50">Isolated from recent operations, per source rules.</p>
            <div class="mt-6">
                <svg viewBox="0 0 320 120" class="h-32 w-full" role="img" aria-label="Historical TSMS reports by year">
                    <path d="{{ $historyArea['area'] }}" fill="oklch(80% 0.12 78 / 0.16)" />
                    <path d="{{ $historyArea['line'] }}" fill="none" stroke="oklch(80% 0.12 78)" stroke-width="2.5" vector-effect="non-scaling-stroke" />
                    @foreach ($historyArea['dots'] as $dot)
                        <circle cx="{{ $dot[0] }}" cy="{{ $dot[1] }}" r="3" fill="oklch(80% 0.12 78)" />
                    @endforeach
                </svg>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-[11px] font-semibold tabular-nums text-base-content/45">
                    @foreach ($historyByYear as $row)
                        <span>{{ $row['label'] }} · {{ number_format($row['total']) }}</span>
                    @endforeach
                </div>
            </div>
            <div class="mt-6 border-t border-base-300 pt-5">
                <p class="mb-3 text-xs font-semibold uppercase tracking-[0.1em] text-base-content/50">Top service types</p>
                <div class="flex flex-wrap gap-2">
                    @forelse ($historyByType as $row)
                        <x-admin.badge tone="warning">{{ $row['label'] }} · {{ number_format($row['total']) }}</x-admin.badge>
                    @empty
                        <span class="text-xs text-base-content/45">No service-type data.</span>
                    @endforelse
                </div>
            </div>
        </div>
    </section>

    <section aria-label="Recent historical records" class="admin-surface overflow-hidden">
        <div class="border-b border-base-300 px-7 py-5 sm:px-8">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">12 · Recent log</p>
            <h2 class="mt-1.5 font-display text-lg font-semibold tracking-tight">Latest historical TSMS records</h2>
        </div>
        <div class="admin-scrollbar hidden max-h-96 overflow-y-auto lg:block">
            <table class="data-table w-full text-left">
                <thead><tr><th>CSR</th><th>Account</th><th>Type</th><th>Status</th><th>TSP</th><th>Branch</th><th>Date</th></tr></thead>
                <tbody>
                    @forelse ($historyRecords as $row)
                        <tr>
                            <td class="font-semibold">{{ $row['csr'] ?: '—' }}</td>
                            <td>{{ $row['account'] ?: '—' }}</td>
                            <td>{{ $row['type'] ?: '—' }}</td>
                            <td>{{ $row['status'] ?: '—' }}</td>
                            <td>{{ $row['tsp'] ?: '—' }}</td>
                            <td>{{ $row['branch'] ?: '—' }}</td>
                            <td class="tabular-nums">{{ $row['date'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center">No historical records available.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="space-y-3 p-5 lg:hidden">
            @forelse ($historyRecords as $row)
                <div class="rounded-xl border border-base-300 bg-base-100 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold">{{ $row['csr'] ?: '—' }}</span>
                        <span class="shrink-0 text-xs tabular-nums text-base-content/50">{{ $row['date'] ?: '—' }}</span>
                    </div>
                    <div class="mt-1 text-sm text-base-content/80">{{ $row['account'] ?: '—' }}</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full bg-warning/15 px-2 py-0.5 font-semibold text-warning-content">{{ $row['type'] ?: '—' }}</span>
                        <span class="rounded-full bg-base-200 px-2 py-0.5 font-semibold text-base-content/70">{{ $row['status'] ?: '—' }}</span>
                    </div>
                    <div class="mt-2 text-xs text-base-content/45">{{ $row['tsp'] ?: '—' }} · {{ $row['branch'] ?: '—' }}</div>
                </div>
            @empty
                <p class="text-sm text-base-content/45">No historical records available.</p>
            @endforelse
        </div>
    </section>

    <footer class="flex flex-col gap-2 px-1 text-[11px] text-base-content/35 sm:flex-row sm:items-center sm:justify-between">
        <span>President command view</span>
        <span>All metrics computed live from imported source tables — Product Database, Service Requests, Technical Personnel, Historical TSMS.</span>
    </footer>
</div>
