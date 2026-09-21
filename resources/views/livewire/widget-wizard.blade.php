<div class="mx-auto max-w-5xl space-y-6">
    <x-admin.page-header eyebrow="Visualize" title="Create a data visualization" description="Pick a table, choose a visual, and add it to a dashboard. The widget is generated from the table's live data — no formulas to write.">
        <x-slot name="actions">
            @if ($dashboardId)
                <a href="{{ route('dashboards.show', $dashboardId) }}" wire:navigate class="admin-secondary-button">Cancel</a>
            @endif
        </x-slot>
    </x-admin.page-header>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        {{-- Form --}}
        <div class="space-y-6">
            <section class="admin-surface p-5">
                <h2 class="text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">1 · Source and destination</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Table</label>
                        <select wire:model.live="tableKey" class="admin-control w-full">
                            <option value="">-- choose a table --</option>
                            @foreach ($tables as $table)
                                <option value="{{ $table['key'] }}">{{ $table['label'] }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('tableKey')" class="mt-1.5" />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Dashboard</label>
                        <select wire:model.live="dashboardId" class="admin-control w-full">
                            <option value="">-- create a new one --</option>
                            @foreach ($dashboards as $dashboard)
                                <option value="{{ $dashboard->id }}">{{ $dashboard->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('dashboardId')" class="mt-1.5" />
                    </div>
                </div>

                @if (! $dashboardId)
                    <div class="mt-4">
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">New dashboard name</label>
                        <input type="text" wire:model="newDashboardName" class="admin-control w-full" placeholder="e.g. Visayas operations">
                        <x-input-error :messages="$errors->get('newDashboardName')" class="mt-1.5" />
                    </div>
                @endif
            </section>

            <section class="admin-surface p-5">
                <h2 class="text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">2 · Visual</h2>

                <div class="mt-4 space-y-4">
                    @if (count($datasets) > 0)
                        <div>
                            <p class="mb-2 text-xs font-semibold text-base-content/55">Charts from this table's data</p>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach ($datasetWidgets as $type)
                                    @if (isset($widgetDefinitions[$type]))
                                        <button type="button" wire:click="$set('widgetType', '{{ $type }}')"
                                                class="rounded-lg border p-3 text-left text-xs font-semibold transition {{ $widgetType === $type ? 'border-primary bg-primary/10 text-primary' : 'border-base-300 hover:border-primary/40' }}">
                                            {{ $widgetDefinitions[$type]['title'] }}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (count($metrics) > 0)
                        <div>
                            <p class="mb-2 text-xs font-semibold text-base-content/55">Numbers from this table</p>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach ($metricWidgets as $type)
                                    @if (isset($widgetDefinitions[$type]))
                                        <button type="button" wire:click="$set('widgetType', '{{ $type }}')"
                                                class="rounded-lg border p-3 text-left text-xs font-semibold transition {{ $widgetType === $type ? 'border-primary bg-primary/10 text-primary' : 'border-base-300 hover:border-primary/40' }}">
                                            {{ $widgetDefinitions[$type]['title'] }}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (count($datasets) === 0 && count($metrics) === 0)
                        <p class="rounded-md border border-dashed border-base-300 px-4 py-6 text-center text-sm text-base-content/50">
                            Choose a table with data to see the available visuals.
                        </p>
                    @endif
                </div>
            </section>

            <section class="admin-surface p-5">
                <h2 class="text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">3 · What it shows</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @if (in_array($widgetType, $datasetWidgets, true))
                        <div>
                            <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Dataset</label>
                            <select wire:model="dataset" class="admin-control w-full">
                                @foreach ($datasets as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('dataset')" class="mt-1.5" />
                        </div>
                    @elseif (in_array($widgetType, $metricWidgets, true))
                        <div>
                            <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Number</label>
                            <select wire:model="metric" class="admin-control w-full">
                                @foreach ($metrics as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('metric')" class="mt-1.5" />
                        </div>
                    @endif

                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Title</label>
                        <input type="text" wire:model="title" class="admin-control w-full" maxlength="80">
                        <x-input-error :messages="$errors->get('title')" class="mt-1.5" />
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end border-t border-base-300 pt-4">
                    <button type="button" wire:click="create" class="admin-primary-button" wire:loading.attr="disabled" wire:target="create">
                        <x-mary-icon name="o-sparkles" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="create">Add to dashboard</span>
                        <span wire:loading wire:target="create">Adding...</span>
                    </button>
                </div>
            </section>
        </div>

        {{-- Live preview --}}
        <div class="space-y-3">
            <h2 class="text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">Preview</h2>

            @if ($preview)
                <div class="admin-surface p-4">
                    @include($preview['view'], $preview['data'])
                </div>
                <p class="text-xs text-base-content/45">This is the exact widget that will be added. You can fine-tune its settings later from the dashboard's Customize mode.</p>
            @else
                <p class="rounded-md border border-dashed border-base-300 px-4 py-10 text-center text-sm text-base-content/50">
                    Pick a table and a visual to preview it here.
                </p>
            @endif
        </div>
    </div>
</div>
