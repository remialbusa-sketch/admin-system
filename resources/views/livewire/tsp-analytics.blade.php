@php
    $kpiToneClasses = [
        'primary' => 'bg-primary/10 text-primary',
        'info' => 'bg-info/10 text-info',
        'success' => 'bg-success/10 text-success',
        'warning' => 'bg-warning/15 text-warning-content',
    ];
@endphp

<div class="space-y-5">
    <x-admin.page-header
        eyebrow="Performance intelligence"
        title="TSP Analytics"
        description="Coverage and workload for company technical service personnel, sourced from the Personnel list and service records."
    >
        <x-slot name="actions">
            <a href="{{ route('personnel') }}" wire:navigate class="admin-primary-button">
                View personnel
                <x-mary-icon name="o-arrow-right" class="h-4 w-4" />
            </a>
        </x-slot>
    </x-admin.page-header>

    <x-admin.filter-bar>
        <span class="text-xs text-base-content/55">Filters</span>
        <select wire:model.live="region" class="admin-control min-w-[150px]" aria-label="Filter by region">
            <option>All regions</option>
            @foreach (['NCR', 'North Luzon', 'Visayas', 'Mindanao'] as $regionOption)
                <option>{{ $regionOption }}</option>
            @endforeach
        </select>
        <select wire:model.live="tspName" class="admin-control min-w-[180px]" aria-label="Filter by TSP">
            <option>All TSPs</option>
            @foreach ($tspOptions as $option)
                <option>{{ $option }}</option>
            @endforeach
        </select>
        <select wire:model.live="branch" class="admin-control min-w-[140px]" aria-label="Filter by branch">
            <option>All branches</option>
            @foreach ($branchOptions as $option)
                <option>{{ $option }}</option>
            @endforeach
        </select>
        <div class="flex items-center gap-1.5">
            <input type="date" wire:model.live="dateFrom" class="admin-control w-[150px]" aria-label="Date from" placeholder="From" />
            <span class="text-xs text-base-content/45">to</span>
            <input type="date" wire:model.live="dateTo" class="admin-control w-[150px]" aria-label="Date to" placeholder="To" />
        </div>
        <button type="button" wire:click="applyPeriod" class="admin-secondary-button">Last 30 days</button>
    </x-admin.filter-bar>

    <section class="admin-surface grid grid-cols-1 divide-y divide-base-300 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-4" aria-label="TSP KPI summary">
        @foreach ($kpis as $kpi)
            <div class="flex items-start gap-3 p-5">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md {{ $kpiToneClasses[$kpi['tone']] }}">
                    <x-mary-icon :name="$kpi['icon']" class="h-4 w-4" />
                </span>
                <div>
                    <p class="text-xs font-semibold text-base-content/55">{{ $kpi['label'] }}</p>
                    <p class="metric-value mt-1.5 text-2xl font-semibold text-base-content">{{ $kpi['value'] }}</p>
                    <p class="mt-1 text-[11px] text-base-content/45">{{ $kpi['context'] }}</p>
                </div>
            </div>
        @endforeach
    </section>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(340px,0.8fr)]">
        <section class="admin-surface p-5 sm:p-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-base font-bold text-base-content">Completed technical reports</h2>
                    <p class="mt-1 text-xs text-base-content/55">Weekly completion volume (last 4 weeks)</p>
                </div>
            </div>

            <div class="mt-8 flex h-56 items-end gap-5 border-b border-l border-base-300 px-4 sm:gap-10" aria-label="Completed technical reports trend chart">
                @php
                    $maxResolved = max(1, (int) collect($trend)->max('resolved'));
                @endphp
                @foreach ($trend as $point)
                    <div class="relative flex h-full flex-1 items-end justify-center">
                        <div class="w-5 bg-primary/75 transition hover:bg-primary sm:w-7" style="height: {{ round(($point['resolved'] / $maxResolved) * 100) }}%" title="Resolved {{ $point['resolved'] }}"></div>
                        <span class="absolute -bottom-6 left-1/2 -translate-x-1/2 text-[10px] font-bold text-base-content/45">{{ $point['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="admin-surface p-5 sm:p-6">
            <div>
                <h2 class="text-base font-bold text-base-content">Coverage notes</h2>
                <p class="mt-1 text-xs text-base-content/55">Operational context for this reporting window</p>
            </div>
            <dl class="mt-5 divide-y divide-base-300">
                @php
                    $topRegion = collect($regionalData)->sortByDesc('active')->first();
                    $busiestRegion = collect($regionalData)->sortByDesc('open')->first();
                @endphp
                <div class="flex items-center justify-between gap-4 py-3 first:pt-0">
                    <dt class="text-xs text-base-content/55">Highest TSP coverage</dt>
                    <dd class="text-sm font-bold text-base-content">{{ $topRegion['region'] ?? '—' }} - {{ number_format($topRegion['active'] ?? 0) }} TSPs</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-xs text-base-content/55">Highest open workload</dt>
                    <dd class="text-sm font-bold text-base-content">{{ $busiestRegion['region'] ?? '—' }} - {{ number_format($busiestRegion['open'] ?? 0) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-xs text-base-content/55">Total active TSPs</dt>
                    <dd class="text-sm font-bold text-base-content">{{ number_format($totalActive) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3 last:pb-0">
                    <dt class="text-xs text-base-content/55">Reporting source</dt>
                    <dd class="text-sm font-bold text-base-content">Personnel + service records</dd>
                </div>
            </dl>
        </section>
    </div>

    <section class="admin-surface overflow-hidden">
        <div class="border-b border-base-300 px-4 py-3 text-sm font-semibold text-base-content">
            Per-TSP performance
            <span class="ml-2 text-xs font-normal text-base-content/45">{{ number_format($filteredReports) }} reports in scope</span>
        </div>
        <div class="admin-scrollbar overflow-x-auto">
            <table class="data-table w-full min-w-[680px] text-left">
                <thead>
                    <tr>
                        <th>TSP</th>
                        <th class="text-right">Reports</th>
                        <th class="text-right">Completed</th>
                        <th class="text-right">Completion rate</th>
                        <th class="text-right">Avg repair (h)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topTsp as $row)
                        <tr>
                            <td class="font-bold text-base-content">{{ $row['tsp_name'] }}</td>
                            <td class="tabular-nums text-right text-base-content/70">{{ number_format($row['reports']) }}</td>
                            <td class="tabular-nums text-right text-base-content/70">{{ number_format($row['completed']) }}</td>
                            <td class="tabular-nums text-right">
                                <x-admin.badge :tone="$row['completion_rate'] >= 90 ? 'success' : ($row['completion_rate'] >= 75 ? 'warning' : 'error')">{{ $row['completion_rate'] }}%</x-admin.badge>
                            </td>
                            <td class="tabular-nums text-right text-base-content/70">{{ number_format($row['avg_repair'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-base-content/55">No TSP performance data in this scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <x-admin.table caption="Regional performance">
        <thead>
            <tr>
                <th>Region</th>
                <th class="text-right">Active TSPs</th>
                <th class="text-right">Open records</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($regionalData as $row)
                <tr>
                    <td class="font-bold text-base-content">{{ $row['region'] }}</td>
                    <td class="tabular-nums text-right text-base-content/70">{{ number_format($row['active']) }}</td>
                    <td class="tabular-nums text-right text-base-content/70">{{ number_format($row['open']) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-4 py-10 text-center text-sm text-base-content/55">No regional data in this scope.</td></tr>
            @endforelse
        </tbody>
    </x-admin.table>
</div>
