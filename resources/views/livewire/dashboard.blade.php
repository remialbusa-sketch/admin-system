@php
    $toneText = ['primary' => 'text-primary', 'success' => 'text-success', 'info' => 'text-info', 'warning' => 'text-warning', 'error' => 'text-error', 'neutral' => 'text-base-content/70'];
    $toneFill = ['primary' => 'bg-primary', 'success' => 'bg-success', 'info' => 'bg-info', 'warning' => 'bg-warning', 'error' => 'bg-error', 'neutral' => 'bg-base-300'];
    $ragBg = ['green' => 'bg-success', 'amber' => 'bg-warning', 'red' => 'bg-error'];
    $ragText = ['green' => 'text-success', 'amber' => 'text-warning', 'red' => 'text-error'];
    $ragGlyph = ['green' => '✓', 'amber' => '!', 'red' => '✕'];
    $ragLabel = ['green' => 'on track', 'amber' => 'watch', 'red' => 'critical'];
    $signalTones = [
        'danger' => 'bg-error/10 text-error',
        'warning' => 'bg-warning/15 text-warning-content',
        'success' => 'bg-success/10 text-success',
    ];
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
    $brandTones = ['primary', 'success', 'warning', 'info', 'error', 'secondary'];
    $typeTones = ['primary', 'success', 'info', 'warning', 'error', 'secondary', 'accent', 'neutral'];

    // Leading installed brands → donut (top 6 + Others)
    $brandRows = collect($productsByBrand)->map(fn ($r) => ['label' => $r['label'], 'value' => (int) $r['total']])->values()->all();
    $brandDonut = [];
    foreach (array_slice($brandRows, 0, 6) as $i => $row) {
        $brandDonut[] = ['label' => $row['label'], 'value' => $row['value'], 'tone' => $brandTones[$i % count($brandTones)]];
    }
    $brandOthers = array_sum(array_column(array_slice($brandRows, 6), 'value'));
    if ($brandOthers > 0) {
        $brandDonut[] = ['label' => 'Others', 'value' => $brandOthers, 'tone' => 'neutral'];
    }
    $brandTotal = max(1, array_sum(array_column($brandDonut, 'value')));

    // Workload by type → stacked composition bar (top 5 + Others so segments stay readable)
    $typeRows = collect($topTypes)->map(fn ($r) => ['label' => $r['label'], 'value' => (int) $r['total']])->values()->all();
    $typeChart = array_slice($typeRows, 0, 5);
    if (count($typeRows) > 5) {
        $typeChart[] = ['label' => 'Others', 'value' => array_sum(array_column(array_slice($typeRows, 5), 'value'))];
    }
    $typeChartTotal = max(1, array_sum(array_column($typeChart, 'value')));

    $branchMax = max(1, (int) collect($byBranch)->max('total'));
    $nBranches = count($byBranch);
    $branchStep = $nBranches > 0 ? (360 - 16) / $nBranches : 1;
    $branchBarW = $branchStep * 0.55;

    $tspMax = max(1, (int) collect($topTsps)->max('total'));
    $sbMax = max(1, (int) collect($topBrands)->max('total'));

    $hyMax = max(1, (int) collect($historyByYear)->max('total'));
    $nHy = count($historyByYear);
    $hyStep = $nHy > 0 ? (320 - 12) / $nHy : 1;
    $hyBarW = $hyStep * 0.55;
    $hyShowLabels = $nHy <= 8;
@endphp

<div class="mx-auto w-full max-w-none space-y-8 pb-4">
    <header class="border-b border-base-300 pb-6 pt-2">
        <div class="flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
            <div class="min-w-0">
                <p class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.18em] text-primary">
                    <span class="inline-block h-px w-6 bg-primary/60"></span>
                    Executive reporting
                </p>
                <h1 class="font-display text-3xl font-semibold tracking-tight text-base-content sm:text-4xl">Home</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-base-content/55">A command view over the installed base, service operations, and isolated historical records — computed live from the imported source tables.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <a href="{{ route('installed-products') }}" wire:navigate class="admin-secondary-button">Product Database <x-mary-icon name="o-arrow-right" class="h-4 w-4" /></a>
                <a href="{{ route('tsp-analytics') }}" wire:navigate class="admin-primary-button">Personnel Analytics</a>
            </div>
        </div>
        <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-base-300 pt-4">
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
    <section aria-label="Headline metrics" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        @if ($lead)
        <div class="admin-surface relative overflow-hidden p-6 sm:p-8 xl:col-span-1">
            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-primary/[0.07] blur-2xl"></div>
            <div class="relative">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-base-content/50">{{ $lead['label'] }}</p>
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-success/15 text-[10px] font-black text-success" title="{{ $ragLabel[$lead['rag']] }}">{{ $ragGlyph[$lead['rag']] }}</span>
                </div>
                <p class="mt-5 font-display text-6xl font-semibold leading-none tracking-tight tabular-nums text-base-content sm:text-7xl">{{ number_format($lead['value']) }}</p>
                <p class="mt-2 text-xs uppercase tracking-[0.1em] text-base-content/40">units installed</p>
                <div class="mt-6 flex flex-wrap gap-2">
                    <span class="rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">{{ $lead['context'] }}</span>
                    <a href="{{ $lead['href'] ?? '#' }}" wire:navigate class="inline-flex items-center gap-1 text-[11px] font-semibold text-base-content/50 underline-offset-2 hover:text-primary hover:underline">Open table <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5" /></a>
                </div>
            </div>
        </div>
        @endif
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:col-span-2">
            @foreach ($rest as $kpi)
                @php $kpiHref = $kpi['href'] ?? null; $kpiTag = $kpiHref ? 'a' : 'div'; @endphp
                <{{ $kpiTag }} @if ($kpiHref) href="{{ $kpiHref }}" wire:navigate @endif
                     class="admin-surface flex flex-col justify-between p-6 {{ $kpiHref ? 'cursor-pointer transition hover:bg-base-200/40' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 truncate text-[11px] font-bold uppercase tracking-[0.14em] text-base-content/45">{{ $kpi['label'] }}</p>
                        <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full {{ $ragBg[$kpi['rag']] }} text-[9px] font-black text-base-100" title="{{ $ragLabel[$kpi['rag']] }}">{{ $ragGlyph[$kpi['rag']] }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-baseline gap-x-2">
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
                                                        <svg viewBox="0 0 100 22" preserveAspectRatio="none" class="h-6 w-20 shrink-0"><polyline points="{{ $pts }}" fill="none" stroke="{{ $kpi['trend'] >= 0 ? 'var(--color-success)' : 'var(--color-error)' }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" /></svg>
                        @endif
                    </div>
                </{{ $kpiTag }}>
            @endforeach
        </div>
    </section>

    <section aria-label="Supporting metrics" class="admin-surface grid grid-cols-1 divide-y divide-base-300 sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-3 lg:divide-x">
        @foreach ($secondary as $kpi)
            @php $kpiHref = $kpi['href'] ?? null; $kpiTag = $kpiHref ? 'a' : 'div'; @endphp
            <{{ $kpiTag }} @if ($kpiHref) href="{{ $kpiHref }}" wire:navigate @endif
                 class="group flex items-center gap-4 px-6 py-4 {{ $kpiHref ? 'cursor-pointer transition hover:bg-base-200/50' : '' }}">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $ragBg[$kpi['rag']] }}/15 text-sm font-black {{ $ragText[$kpi['rag']] }}" aria-hidden="true">{{ $ragGlyph[$kpi['rag']] }}</div>
                <div class="min-w-0">
                    <p class="truncate text-[11px] font-bold uppercase tracking-[0.12em] text-base-content/45">{{ $kpi['label'] }}</p>
                    <p class="mt-1 font-display text-xl font-semibold leading-none tabular-nums">{{ number_format($kpi['value']) }}{{ $kpi['suffix'] ?? '' }}</p>
                    <p class="mt-1 truncate text-[10px] text-base-content/35">{{ $kpi['context'] }}</p>
                </div>
            </{{ $kpiTag }}>
        @endforeach
    </section>

    <section aria-label="Service status" class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.35fr)]">
        <div class="admin-surface p-6 sm:p-7">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">01 · Status mix</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Outcome share</h2>
            <p class="mt-1 text-xs text-base-content/50">Requests by resolution state</p>
            <div class="mt-7 flex flex-1 flex-col items-center justify-center gap-7">
                <div class="relative h-40 w-40 shrink-0">
                    <svg viewBox="0 0 42 42" class="h-40 w-40 -rotate-90">
                        @php $offset = 0; $circ = 2 * pi() * 15.9155; @endphp
                        @foreach ($statusDonut as $seg)
                            @php
                                $frac = $statusTotal > 0 ? $seg['value'] / $statusTotal : 0;
                                $len = $frac * $circ;
                                $stroke = match ($seg['tone']) {
                                    'success' => 'var(--color-success)',
                                    'warning' => 'var(--color-warning)',
                                    'error' => 'var(--color-error)',
                                    default => 'var(--color-base-300)',
                                };
                            @endphp
                            <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $stroke }}" stroke-width="6"
                                stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"></circle>
                            @php $offset += $len; @endphp
                        @endforeach
                        <circle cx="21" cy="21" r="12.9" fill="var(--color-base-100)" />
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-display text-2xl font-semibold tabular-nums">{{ $completionRate }}%</span>
                        <span class="text-[9px] font-bold uppercase tracking-[0.14em] text-base-content/40">done</span>
                    </div>
                </div>
                <ul class="w-full min-w-0 space-y-3">
                    @foreach ($statusDonut as $seg)
                        <li class="flex items-center gap-3 text-sm">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $toneFill[$seg['tone']] }}"></span>
                            <span class="min-w-0 flex-1 truncate text-base-content/65">{{ $seg['label'] }}</span>
                            <span class="font-semibold tabular-nums">{{ number_format($seg['value']) }}</span>
                            <span class="w-12 text-right text-[11px] tabular-nums text-base-content/35">{{ round(($seg['value'] / $statusTotal) * 100) }}%</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="admin-surface flex h-full flex-col overflow-hidden">
            <div class="border-b border-base-300 px-6 py-5 sm:px-7">
                <h2 class="font-display text-lg font-semibold tracking-tight">Work by status</h2>
                <p class="mt-1 text-xs text-base-content/50">Full status breakdown · {{ $selectedRegion }}</p>
            </div>
            <div class="admin-scrollbar flex-1 overflow-x-auto">
                <table class="data-table w-full text-left">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-right">Records</th>
                            <th class="text-right">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($statusSummary as $row)
                            <tr>
                                <td><x-admin.badge :tone="$row['tone']">{{ $row['label'] }}</x-admin.badge></td>
                                <td class="tabular-nums text-right font-semibold text-base-content">{{ $row['count'] }}</td>
                                <td class="tabular-nums text-right text-base-content/55">{{ $row['share'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center">No service requests in scope.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section aria-label="Attention needed" class="admin-surface p-6 sm:p-7">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">02 · Attention</p>
                <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">What needs action</h2>
                <p class="mt-1 text-xs text-base-content/50">Signals that may need an operator</p>
            </div>
            <a href="{{ route('service-requests') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">Open requests <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5" /></a>
        </div>
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach ($attentionSignals as $item)
                @php $toneClass = $signalTones[$item['tone']] ?? 'bg-base-200 text-base-content/60'; @endphp
                <div class="flex gap-3 rounded-xl border border-base-300 bg-base-100 p-4">
                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md {{ $toneClass }}">
                        <x-mary-icon :name="$item['icon']" class="h-4 w-4" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold leading-5 text-base-content">{{ $item['title'] }}</p>
                        <p class="mt-1 text-xs leading-5 text-base-content/55">{{ $item['detail'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section aria-label="Performance and history" class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.55fr)_minmax(0,0.85fr)]">
        <div class="admin-surface flex flex-col p-6 sm:p-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">03 · Intake trend</p>
                    <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Service request intake</h2>
                    <p class="mt-1 text-xs text-base-content/50">New requests by month · last 12 months</p>
                </div>
                <span class="inline-flex shrink-0 items-center gap-2 self-start rounded-full px-3 py-1.5 text-xs font-bold {{ $requestMoM >= 0 ? 'bg-success/10 text-success' : 'bg-error/10 text-error' }}">
                    <span class="text-[10px] opacity-70">MoM</span>
                    {{ $requestMoM >= 0 ? '▲' : '▼' }} {{ abs($requestMoM) }}%
                </span>
            </div>
            @if (!empty($requestArea['line']))
                <div class="mt-7 flex flex-1 flex-col justify-center">
                    <svg viewBox="0 0 640 140" class="h-52 w-full" role="img" aria-label="Service request intake trend">
                        <defs>
                            <linearGradient id="areaFade" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="var(--color-primary)" stop-opacity="0.18" />
                                <stop offset="100%" stop-color="var(--color-primary)" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <path d="{{ $requestArea['area'] }}" fill="url(#areaFade)" />
                        <path d="{{ $requestArea['line'] }}" fill="none" stroke="var(--color-primary)" stroke-width="2.5" vector-effect="non-scaling-stroke" />
                        @foreach ($requestArea['dots'] as $dot)
                            <circle cx="{{ $dot[0] }}" cy="{{ $dot[1] }}" r="3" fill="var(--color-primary)" />
                        @endforeach
                    </svg>
                    <div class="mt-2 flex justify-between text-[10px] font-semibold uppercase tracking-wide text-base-content/35">
                        <span>{{ $requestTrend[0]['short'] }}</span>
                        <span>{{ $requestTrend[intdiv(count($requestTrend), 2)]['short'] }}</span>
                        <span>{{ $requestTrend[count($requestTrend) - 1]['short'] }}</span>
                    </div>
                </div>
            @else
                <div class="mt-6 rounded-lg border border-dashed border-base-300 p-6 text-center">
                    <p class="text-sm font-semibold text-base-content/70">Not enough intake data yet</p>
                    <p class="mt-1 text-xs text-base-content/45">Two or more months of service requests are needed to draw the trend.</p>
                </div>
            @endif
        </div>

        <div class="admin-surface p-6 sm:p-7">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">04 · History</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Historical TSMS</h2>
            <p class="mt-1 text-xs text-base-content/50">Reports by year · isolated from recent operations</p>
            @if ($nHy > 0)
                <div class="mt-7">
                    <svg viewBox="0 0 320 156" class="h-36 w-full" role="img" aria-label="Historical TSMS reports by year">
                        @foreach ($historyByYear as $i => $row)
                            @php
                                $bh = ($row['total'] / $hyMax) * 114;
                                $x = 6 + $i * $hyStep + ($hyStep - $hyBarW) / 2;
                                $y = 146 - $bh;
                            @endphp
                            <g>
                                <title>{{ $row['label'] }} · {{ number_format($row['total']) }} reports</title>
                                <rect x="{{ round($x, 1) }}" y="{{ round($y, 1) }}" width="{{ round($hyBarW, 1) }}" height="{{ max(2, round($bh, 1)) }}" rx="3" fill="var(--color-warning)"></rect>
                                @if ($hyShowLabels)
                                    <text x="{{ round($x + $hyBarW / 2, 1) }}" y="{{ round($y - 6, 1) }}" text-anchor="middle" fill="currentColor" class="text-[9px] font-semibold tabular-nums text-base-content/45">{{ number_format($row['total']) }}</text>
                                    <text x="{{ round($x + $hyBarW / 2, 1) }}" y="151" text-anchor="middle" fill="currentColor" class="text-[8px] font-bold uppercase tracking-wide text-base-content/35">{{ $row['label'] }}</text>
                                @endif
                            </g>
                        @endforeach
                    </svg>
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
            @else
                <div class="mt-7 rounded-lg border border-dashed border-base-300 p-6 text-center">
                    <p class="text-sm font-semibold text-base-content/70">No historical TSMS records</p>
                    <p class="mt-1 text-xs text-base-content/45">Import a Historical TSMS table to see the year-by-year view.</p>
                </div>
            @endif
        </div>
    </section>

    <section aria-label="Distribution" class="grid grid-cols-1 gap-5 xl:grid-cols-2">
        <div class="admin-surface flex flex-col p-6 sm:p-7">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">05 · Base composition</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Leading installed brands</h2>
            <p class="mt-1 text-xs text-base-content/50">Top equipment brands by record count</p>
            <div class="my-auto mt-7 flex flex-col items-center gap-8 lg:flex-row lg:items-center">
                <div class="relative h-40 w-40 shrink-0">
                    <svg viewBox="0 0 42 42" class="h-40 w-40 -rotate-90">
                        @php $offset = 0; $circ = 2 * pi() * 15.9155; @endphp
                        @foreach ($brandDonut as $seg)
                            @php
                                $len = ($seg['value'] / $brandTotal) * $circ;
                            @endphp
                            <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $chartColors[$seg['tone']] }}" stroke-width="6"
                                stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"></circle>
                            @php $offset += $len; @endphp
                        @endforeach
                        <circle cx="21" cy="21" r="12.9" fill="var(--color-base-100)" />
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-display text-2xl font-semibold tabular-nums">{{ number_format($brandTotal) }}</span>
                        <span class="text-[9px] font-bold uppercase tracking-[0.14em] text-base-content/40">units</span>
                    </div>
                </div>
                <ul class="w-full min-w-0 flex-1 space-y-2.5">
                    @forelse ($brandDonut as $seg)
                        <li class="flex items-center gap-2.5 text-sm">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $chartColors[$seg['tone']] }}"></span>
                            <span class="min-w-0 flex-1 truncate text-base-content/65">{{ $seg['label'] }}</span>
                            <span class="font-semibold tabular-nums">{{ number_format($seg['value']) }}</span>
                            <span class="w-11 text-right text-[11px] tabular-nums text-base-content/35">{{ round(($seg['value'] / $brandTotal) * 100) }}%</span>
                        </li>
                    @empty
                        <li class="text-sm text-base-content/45">No Product Database data imported.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="admin-surface p-6 sm:p-7">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">06 · Engineer workload</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Top TSPs by work logged</h2>
            <p class="mt-1 text-xs text-base-content/50">Technical reports per field engineer</p>
            <div class="mt-6 space-y-3">
                @forelse ($topTsps as $i => $row)
                    <div class="flex items-center gap-3">
                        <span class="w-5 shrink-0 text-right text-[11px] font-black tabular-nums text-base-content/30">{{ $i + 1 }}</span>
                        <span class="w-44 shrink-0 truncate text-sm font-medium text-base-content/80" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                        <div class="h-6 flex-1 overflow-hidden rounded-full bg-base-200/80">
                            <div class="h-full rounded-full bg-gradient-to-r from-warning to-warning/60" style="width: {{ round(($row['total'] / $tspMax) * 100) }}%"></div>
                        </div>
                        <span class="w-10 shrink-0 text-right text-xs font-bold tabular-nums text-base-content/70">{{ number_format($row['total']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/45">No TSP workload data available.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section aria-label="Operations detail" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="admin-surface flex h-full flex-col p-6 sm:p-7">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">07 · By branch</p>
            <h2 class="mt-2 font-display text-lg font-semibold tracking-tight">Service requests</h2>
            <p class="mt-1 text-xs text-base-content/50">Reports per branch</p>
            @if ($nBranches > 0)
                <div class="mt-6 flex flex-1 flex-col justify-center">
                    <svg viewBox="0 0 360 158" class="h-48 w-full" role="img" aria-label="Service requests by branch">
                        @foreach ($byBranch as $i => $row)
                            @php
                                $bh = ($row['total'] / $branchMax) * 112;
                                $x = 8 + $i * $branchStep + ($branchStep - $branchBarW) / 2;
                                $y = 140 - $bh;
                            @endphp
                            <g>
                                <title>{{ $row['label'] }} · {{ number_format($row['total']) }} reports</title>
                                <rect x="{{ round($x, 1) }}" y="{{ round($y, 1) }}" width="{{ round($branchBarW, 1) }}" height="{{ max(2, round($bh, 1)) }}" rx="3" fill="var(--color-info)"></rect>
                                <text x="{{ round($x + $branchBarW / 2, 1) }}" y="{{ round($y - 6, 1) }}" text-anchor="middle" fill="currentColor" class="text-[9px] font-semibold tabular-nums text-base-content/45">{{ number_format($row['total']) }}</text>
                                <text x="{{ round($x + $branchBarW / 2, 1) }}" y="154" text-anchor="middle" fill="currentColor" class="text-[9px] font-semibold uppercase tracking-wide text-base-content/40">{{ mb_substr($row['label'], 0, 6) }}</text>
                            </g>
                        @endforeach
                    </svg>
                </div>
            @else
                <p class="mt-6 text-sm text-base-content/45">No branch data available.</p>
            @endif
        </div>

        <div class="admin-surface flex h-full flex-col p-6 sm:p-7">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">08 · Request types</p>
            <h2 class="mt-2 font-display text-lg font-semibold tracking-tight">Workload by type</h2>
            <p class="mt-1 text-xs text-base-content/50">Composition of all requests</p>
            @if (count($typeChart) > 0)
                <div class="mt-6 flex flex-1 flex-col justify-center">
                    <div class="flex h-4 w-full overflow-hidden rounded-full bg-base-200">
                        @foreach ($typeChart as $i => $row)
                            <div style="width: {{ round(($row['value'] / $typeChartTotal) * 100, 2) }}%; background: {{ $chartColors[$typeTones[$i % count($typeTones)]] }}" title="{{ $row['label'] }} · {{ number_format($row['value']) }}"></div>
                        @endforeach
                    </div>
                    <ul class="mt-5 space-y-2.5">
                        @foreach ($typeChart as $i => $row)
                            <li class="flex items-center gap-2.5 text-xs">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $chartColors[$typeTones[$i % count($typeTones)]] }}"></span>
                                <span class="min-w-0 flex-1 truncate text-base-content/65">{{ $row['label'] }}</span>
                                <span class="font-semibold tabular-nums text-base-content/70">{{ number_format($row['value']) }}</span>
                                <span class="w-9 text-right tabular-nums text-base-content/35">{{ round(($row['value'] / $typeChartTotal) * 100) }}%</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                <p class="mt-6 text-sm text-base-content/45">No service type data available.</p>
            @endif
        </div>

        <div class="admin-surface flex h-full flex-col p-6 sm:p-7">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">09 · Serviced brands</p>
            <h2 class="mt-2 font-display text-lg font-semibold tracking-tight">Equipment most serviced</h2>
            <p class="mt-1 text-xs text-base-content/50">Brands by service requests</p>
            <div class="mt-6 flex flex-1 flex-col justify-center space-y-3.5">
                @forelse ($topBrands as $row)
                    <div class="flex items-center gap-3">
                        <span class="w-28 shrink-0 truncate text-sm text-base-content/70" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-base-200"><div class="h-2 rounded-full bg-accent/80" style="width: {{ round(($row['total'] / $sbMax) * 100) }}%"></div></div>
                        <span class="w-10 text-right text-xs font-semibold tabular-nums">{{ number_format($row['total']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/45">No brand data available.</p>
                @endforelse
            </div>
        </div>
    </section>

    <footer class="flex flex-col gap-2 px-1 text-[11px] text-base-content/35 sm:flex-row sm:items-center sm:justify-between">
        <span>Executive command view</span>
        <span>All metrics computed live from imported source tables — Product Database, Service Requests, Technical Personnel, Historical TSMS.</span>
    </footer>
</div>