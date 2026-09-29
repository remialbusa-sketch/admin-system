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

    {{-- Headline + supporting cards as widgets: same cards, same numbers,
         fully customizable. --}}
    <section aria-label="Technical reports widgets">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-primary">
                    <span class="inline-block h-px w-6 bg-primary/60"></span>
                    Technical reports grid
                </p>
                <p class="mt-1 text-xs text-base-content/50">Live widgets, not fixed cards — gear to configure, drag to reorder.</p>
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
        <x-dashboard.grid :widgets="$tsaGrid['widgets']" :editing="$tsaCustomizing" grid-key="tsa" />
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
