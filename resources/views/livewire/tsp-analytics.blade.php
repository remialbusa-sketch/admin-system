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
            <option value="All TSPs">All TSPs</option>
            @foreach ($tspOptions as $tspValue => $tspLabel)
                <option value="{{ $tspValue }}">{{ $tspLabel }}</option>
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

    {{-- KPI cards as widgets: same cards, same numbers, fully customizable. --}}
    <section aria-label="TSP widgets">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-primary">
                    <span class="inline-block h-px w-6 bg-primary/60"></span>
                    TSP grid
                </p>
                <p class="mt-1 text-xs text-base-content/50">Live widgets, not fixed cards — gear to configure, drag to reorder.</p>
            </div>
            <div class="no-print flex flex-wrap items-center gap-2">
                @if ($tspCustomizing)
                    <span class="hidden text-[11px] font-semibold text-base-content/45 sm:inline">Drag to reorder · corner to resize · gear to configure — saved on Done</span>
                    <button type="button" x-on:click="$dispatch('open-modal', { name: 'add-widget' })" class="admin-secondary-button">Add widget</button>
                    <button type="button" wire:click="resetWidgetGrid('tsp')" wire:confirm="Reset this grid to the shipped cards? Your changes will be removed." class="admin-secondary-button">Reset</button>
                    <button type="button" wire:click="toggleCustomizingFor('tsp')" class="admin-secondary-button">Cancel</button>
                    <button type="button" data-grid-done="tsp" class="admin-primary-button">Done</button>
                @elseif ($canCustomizeTsp)
                    <button type="button" wire:click="toggleCustomizingFor('tsp')" class="admin-secondary-button">Customize grid</button>
                @endif
            </div>
        </div>
        <x-dashboard.grid :widgets="$tspGrid['widgets']" :editing="$tspCustomizing" grid-key="tsp" />
    </section>

    {{-- Add widget: one-click presets for this page (same datasets as the grid). --}}
    @if ($tspCustomizing)
        <x-admin.modal name="add-widget" title="Add widget" description="Pick a preset — it lands at the end of the grid with settings you can tune from its gear icon." size="lg">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($tspPresets as $key => $preset)
                    <button type="button" wire:click="addWidgetFor('tsp', '{{ $key }}')" class="group flex flex-col items-start gap-1 rounded-lg border border-primary/30 bg-primary/5 p-4 text-left transition duration-150 hover:-translate-y-0.5 hover:border-primary/60 hover:shadow-lg hover:shadow-primary/10">
                        <span class="text-sm font-bold text-base-content transition-colors group-hover:text-primary">{{ $preset['title'] }}</span>
                        <span class="text-xs leading-5 text-base-content/55">{{ $preset['description'] }}</span>
                    </button>
                @endforeach
            </div>
        </x-admin.modal>
    @endif

    @include('components.dashboard.widget-settings-modal', [
        'wsModal' => $this->widgetSettingsModalName('tsp'),
        'wsGrid' => 'tsp',
        'ws' => $tspState,
        'wsPrefix' => $this->widgetSettingsPrefix('tsp'),
        'wsMetricLabels' => $tspMetricLabels,
        'wsMetricValues' => $tspMetricValues,
        'wsApply' => "applyWidgetSettingsFor('tsp', window.__treeGraphs || {})",
        'wsCancel' => "cancelWidgetSettingsFor('tsp')",
        'wsHint' => 'No data selected — pick a metric above.',
    ])

</div>
