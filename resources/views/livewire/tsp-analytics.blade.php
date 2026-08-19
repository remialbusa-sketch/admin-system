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
        description="Measure technical service personnel coverage, response speed, and resolution performance across the operating network."
    >
        <x-slot name="actions">
            <select wire:model.live="period" class="admin-control min-w-[145px]">
                <option>Last 7 days</option>
                <option>Last 30 days</option>
                <option>Last 90 days</option>
            </select>
            <a href="{{ route('records') }}" wire:navigate class="admin-primary-button">
                View records
                <x-mary-icon name="o-arrow-right" class="h-4 w-4" />
            </a>
        </x-slot>
    </x-admin.page-header>

    <x-admin.filter-bar>
        <span class="text-xs text-base-content/55">Showing data for</span>
        <select wire:model.live="region" class="admin-control min-w-[160px]" aria-label="Filter by region">
            <option>All regions</option>
            @foreach (['NCR', 'North Luzon', 'Visayas', 'Mindanao'] as $regionOption)
                <option>{{ $regionOption }}</option>
            @endforeach
        </select>
        <span class="ml-auto text-xs text-base-content/45">{{ $period }} - updated 10 minutes ago</span>
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
                    <h2 class="text-base font-bold text-base-content">Resolution and SLA trend</h2>
                    <p class="mt-1 text-xs text-base-content/55">Indexed performance across the selected period</p>
                </div>
                <div class="flex items-center gap-4 text-[11px] font-semibold text-base-content/50">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary"></span>Resolved</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-success"></span>SLA</span>
                </div>
            </div>

            <div class="mt-8 flex h-56 items-end gap-5 border-b border-l border-base-300 px-4 sm:gap-10" aria-label="TSP resolution and SLA trend chart">
                @foreach ($trend as $point)
                    <div class="relative flex h-full flex-1 items-end justify-center gap-1.5">
                        <div class="w-5 bg-primary/75 transition hover:bg-primary sm:w-7" style="height: {{ $point['resolved'] }}%" title="Resolved {{ $point['resolved'] }}"></div>
                        <div class="w-5 bg-success/65 transition hover:bg-success sm:w-7" style="height: {{ $point['sla'] }}%" title="SLA {{ $point['sla'] }}"></div>
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
                <div class="flex items-center justify-between gap-4 py-3 first:pt-0">
                    <dt class="text-xs text-base-content/55">Highest active coverage</dt>
                    <dd class="text-sm font-bold text-base-content">NCR - 84 TSPs</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-xs text-base-content/55">Fastest response</dt>
                    <dd class="text-sm font-bold text-base-content">NCR - 2.1h</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3">
                    <dt class="text-xs text-base-content/55">Needs review</dt>
                    <dd class="text-sm font-bold text-warning-content">North Luzon</dd>
                </div>
                <div class="flex items-center justify-between gap-4 py-3 last:pb-0">
                    <dt class="text-xs text-base-content/55">Reporting source</dt>
                    <dd class="text-sm font-bold text-base-content">Records table</dd>
                </div>
            </dl>
        </section>
    </div>

    <x-admin.table caption="Regional performance">
        <thead>
            <tr>
                <th>Region</th>
                <th class="text-right">Active TSPs</th>
                <th class="text-right">Open records</th>
                <th class="text-right">Resolution rate</th>
                <th class="text-right">Avg. response</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($regionalData as $row)
                <tr>
                    <td class="font-bold text-base-content">{{ $row['region'] }}</td>
                    <td class="tabular-nums text-right text-base-content/70">{{ number_format($row['active']) }}</td>
                    <td class="tabular-nums text-right text-base-content/70">{{ number_format($row['open']) }}</td>
                    <td class="tabular-nums text-right"><x-admin.badge :tone="(float) str_replace('%', '', $row['resolved']) >= 92 ? 'success' : 'warning'">{{ $row['resolved'] }}</x-admin.badge></td>
                    <td class="tabular-nums text-right text-base-content/70">{{ $row['response'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-base-content/55">No regional data in this scope.</td></tr>
            @endforelse
        </tbody>
    </x-admin.table>
</div>
