@php
    $metrics ??= [];
    $byStatus ??= [];
    $byTsp ??= [];
    $byBrand ??= [];
    $trend ??= [];
    $avgResponse ??= 0;
@endphp

<div class="space-y-5">
    <x-admin.page-header eyebrow="Analytics" title="Technical Service Analysis" description="Service report completion, TSP workload, and recurring equipment patterns from the imported Technical Reports table.">
        <x-slot name="actions">
            <a href="{{ route('technical-reports') }}" wire:navigate class="admin-secondary-button">Open Technical Reports</a>
        </x-slot>
    </x-admin.page-header>

    <section class="admin-surface grid grid-cols-1 divide-y divide-base-300 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-4" aria-label="Key metrics">
        @foreach ($metrics as $metric)
            <div class="flex items-start justify-between gap-4 p-5">
                <div>
                    <p class="text-xs font-semibold text-base-content/55">{{ $metric['label'] }}</p>
                    <p class="metric-value mt-2 text-3xl font-semibold text-base-content">{{ $metric['value'] }}</p>
                    <p class="mt-2 text-[11px] text-base-content/45">{{ $metric['context'] }}</p>
                </div>
                <span class="flex h-8 w-8 items-center justify-center rounded-md bg-base-200 text-base-content/55">
                    <x-mary-icon :name="$metric['icon']" class="h-4 w-4" />
                </span>
            </div>
        @endforeach
    </section>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
        <section class="admin-surface flex flex-col p-5 sm:p-6">
            <h2 class="text-base font-bold text-base-content">Completed reports (last 7 days)</h2>
            @if (($trendTotal ?? 0) > 0)
                <div class="mt-8 flex h-52 items-end gap-3 border-b border-l border-base-300 px-4 pb-0 pt-4">
                    @foreach ($trend as $point)
                        <div class="flex h-full flex-1 flex-col items-center justify-end gap-2">
                            <span class="tabular-nums text-[10px] font-bold text-base-content/45">{{ $point['value'] }}</span>
                            <div class="w-full max-w-12 bg-primary/75 transition hover:bg-primary" style="height: {{ $point['value'] > 0 ? max(4, $point['value']) : 2 }}%"></div>
                            <span class="text-[10px] font-bold text-base-content/45">{{ $point['day'] }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-6 flex flex-1 items-center justify-center">
                    <div class="w-full rounded-lg border border-dashed border-base-300 p-6 text-center">
                        <p class="text-sm font-semibold text-base-content/70">No reports completed in the last 7 days</p>
                        <p class="mt-1 text-xs text-base-content/45">Completion dates in the imported data are older than this window.</p>
                    </div>
                </div>
            @endif
        </section>

        <section class="admin-surface p-5 sm:p-6">
            <h2 class="text-base font-bold text-base-content">Reports by status</h2>
            <div class="mt-4 space-y-3">
                @forelse ($byStatus as $row)
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="text-base-content/70">{{ $row['label'] }}</span>
                        <span class="font-bold tabular-nums text-base-content">{{ number_format($row['count']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/55">No data.</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
        <section class="admin-surface p-5 sm:p-6">
            <h2 class="text-base font-bold text-base-content">Top TSP workload</h2>
            <div class="mt-4 space-y-3">
                @forelse ($byTsp as $row)
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="truncate text-base-content/70">{{ $row['label'] }}</span>
                        <span class="font-bold tabular-nums text-base-content">{{ number_format($row['count']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/55">No data.</p>
                @endforelse
            </div>
        </section>

        <section class="admin-surface p-5 sm:p-6">
            <h2 class="text-base font-bold text-base-content">Reports by brand</h2>
            <div class="mt-4 space-y-3">
                @forelse ($byBrand as $row)
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="truncate text-base-content/70">{{ $row['label'] }}</span>
                        <span class="font-bold tabular-nums text-base-content">{{ number_format($row['count']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/55">No data.</p>
                @endforelse
            </div>
            <p class="mt-4 text-xs text-base-content/45">Average response time: {{ number_format($avgResponse, 1) }}h</p>
        </section>
    </div>
</div>
