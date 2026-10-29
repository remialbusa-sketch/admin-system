<div class="admin-dashboard-page">
    <x-admin.page-header eyebrow="Technical Reports" title="Technical Service Analysis" description="Explore monthly calls, service types, and models from Technical Reports.">
        <x-slot name="actions">
            <button type="button" onclick="window.print()" class="admin-secondary-button no-print">Print report</button>
            <a href="{{ route('technical-reports') }}" wire:navigate class="admin-primary-button no-print">Open Technical Reports</a>
        </x-slot>
    </x-admin.page-header>

    <x-admin.filter-bar>
        <label class="flex flex-wrap items-center gap-3 text-xs font-medium text-base-content/70">
            Completion trend window
            <select wire:model.live="period" class="admin-control" aria-label="Reporting period">
                @foreach ($periodOptions as $option)
                    <option value="{{ $option }}">Last {{ $option }}</option>
                @endforeach
            </select>
        </label>
        <div class="admin-freshness sm:ml-auto">
            <x-mary-icon name="o-clock" class="h-3.5 w-3.5" />
            <span>Report data as of <span class="font-medium">{{ $freshness }}</span></span>
        </div>
    </x-admin.filter-bar>

    @if ($analysisFilters)
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border border-base-300 bg-base-200/50 px-4 py-2.5 no-print" aria-label="Active analysis filters">
            <span class="text-[11px] font-bold uppercase tracking-[0.12em] text-base-content/50">Filtering every widget</span>
            @foreach ($analysisFilters as $id => $filter)
                <button type="button" wire:click="clearSlicer(@js($id))" class="rounded-full border border-primary/30 bg-primary/10 px-3 py-1 text-xs text-primary" aria-label="Clear {{ $filter['label'] }} filter">
                    {{ $filter['label'] }}: {{ implode(', ', $filter['values']) }} <span aria-hidden="true">×</span>
                </button>
            @endforeach
            <button type="button" wire:click="clearAnalysisFilters" class="admin-secondary-button ml-auto">Clear all</button>
        </div>
    @endif

    {{-- Headline + supporting cards as widgets: same cards, same numbers,
         fully customizable. --}}
    <section aria-label="Technical reports widgets">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="admin-section-heading">Technical reports grid</h2>
                <p class="mt-1 text-xs text-base-content/50">Charts and metrics update together as you filter.</p>
            </div>
            <div class="no-print flex flex-wrap items-center gap-2">
                @if ($tsaCustomizing)
                    <span class="hidden text-[11px] font-semibold text-base-content/45 sm:inline">Drag to reorder · corner to resize · gear to configure — saved on Done</span>
                    <button type="button" x-on:click="$dispatch('open-modal', { name: 'add-widget' })" class="admin-secondary-button">Add widget</button>
                    <button type="button" wire:click="resetWidgetGrid('tsa')" wire:confirm="Reset this grid to the shipped cards? Your changes will be removed." class="admin-secondary-button">Reset</button>
                    <button type="button" wire:click="toggleCustomizingFor('tsa')" class="admin-secondary-button">Cancel</button>
                    <button type="button" data-grid-done="tsa" class="admin-primary-button">Done</button>
                @elseif ($canCustomizeTsa)
                    <button type="button" wire:click="toggleCustomizingFor('tsa')" class="admin-secondary-button">Customize grid</button>
                @endif
            </div>
        </div>
        <details class="mb-4 rounded-lg border border-base-300 px-4 py-3 no-print">
            <summary class="cursor-pointer text-xs font-semibold text-base-content/65">Chart fields</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                @foreach (['date' => 'Call date', 'model' => 'Model', 'service' => 'Service Type'] as $field => $label)
                    <label class="text-xs text-base-content/60">
                        <span class="mb-1 block">{{ $label }}</span>
                        <select wire:model.live="analysisColumns.{{ $field }}" class="admin-control w-full" aria-label="{{ $label }} chart field">
                            <option value="">{{ $field === 'date' ? 'Service start date (default)' : ($field === 'model' ? 'Model (default)' : 'Match Service Type / Type of Request') }}</option>
                            @foreach ($analysisColumnOptions as $key => $column)
                                @if ($field !== 'date' || $column['kind'] === 'date')
                                    <option value="{{ $key }}">{{ $column['label'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
            <p class="mt-3 text-[11px] leading-4 text-base-content/45">Re-points the date, model, and service fields of the reference widgets below the cards. Their slicers filter every widget on this page.</p>
        </details>
        <div wire:loading.class="opacity-50" class="transition-opacity" aria-live="polite">
            <x-dashboard.grid :widgets="$tsaGrid['widgets']" :editing="$tsaCustomizing" grid-key="tsa" />
        </div>
    </section>

    {{-- Add widget: one-click presets for this page (same datasets as the grid). --}}
    @if ($tsaCustomizing)
        <x-admin.modal name="add-widget" title="Add widget" description="Pick a preset — it lands at the end of the grid with settings you can tune from its gear icon." size="lg">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($tsaPresets as $key => $preset)
                    <button type="button" wire:click="addWidgetFor('tsa', '{{ $key }}')" class="group flex flex-col items-start gap-1 rounded-lg border border-primary/30 bg-primary/5 p-4 text-left transition duration-150 hover:-translate-y-0.5 hover:border-primary/60 hover:shadow-lg hover:shadow-primary/10">
                        <span class="text-sm font-bold text-base-content transition-colors group-hover:text-primary">{{ $preset['title'] }}</span>
                        <span class="text-xs leading-5 text-base-content/55">{{ $preset['description'] }}</span>
                    </button>
                @endforeach
            </div>
        </x-admin.modal>
    @endif

    @include('components.dashboard.widget-settings-modal', [
        'wsModal' => $this->widgetSettingsModalName('tsa'),
        'wsGrid' => 'tsa',
        'ws' => $tsaState,
        'wsPrefix' => $this->widgetSettingsPrefix('tsa'),
        'wsMetricLabels' => $tsaMetricLabels,
        'wsMetricValues' => $tsaMetricValues,
        'wsApply' => "applyWidgetSettingsFor('tsa', window.__treeGraphs || {})",
        'wsCancel' => "cancelWidgetSettingsFor('tsa')",
        'wsHint' => 'No data selected — pick a metric above.',
    ])

    <footer class="flex flex-col gap-2 px-1 text-[11px] text-base-content/35 sm:flex-row sm:items-center sm:justify-between">
        <span>Technical Reports overview · click any card, segment, or bar to open the reports behind it — the grid arrives pre-filtered, with a one-click clear.</span>
        <span>All metrics computed live from the imported Technical Reports table — never hardcoded.</span>
    </footer>
</div>
