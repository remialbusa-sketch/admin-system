<div class="mx-auto w-full max-w-none space-y-8 pb-4">
    <header class="border-b border-base-300 pb-6 pt-2">
        <div class="flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
            @if ($dashboard)
                @if ($editingHeader)
                    <div class="min-w-0 flex-1 space-y-3">
                        <input type="text" wire:model="editingName" @disabled($dashboard->is_system) class="admin-control w-full text-3xl font-semibold tracking-tight sm:text-4xl" placeholder="Dashboard title" autofocus>
                        <textarea wire:model="editingDescription" rows="2" class="admin-control w-full max-w-2xl text-sm leading-6" placeholder="Subtitle — what this dashboard is for (optional)"></textarea>
                        <x-input-error :messages="$errors->get('editingName')" />
                        <x-input-error :messages="$errors->get('editingDescription')" />
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="saveHeader" class="admin-primary-button">Save</button>
                            <button type="button" wire:click="cancelHeaderEdit" class="admin-secondary-button">Cancel</button>
                        </div>
                    </div>
                @else
                    <div class="min-w-0">
                        <p class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.18em] text-primary">
                            <span class="inline-block h-px w-6 bg-primary/60"></span>
                            {{ $canEditDashboard ? 'Dashboard' : 'Dashboard · view only' }}
                        </p>
                        <div class="flex items-center gap-3">
                            <h1 class="font-display text-3xl font-semibold tracking-tight text-base-content sm:text-4xl">{{ $dashboard->name }}</h1>
                            @if ($canEditDashboard)
                                <button type="button" wire:click="startHeaderEdit" class="admin-icon-button" aria-label="Edit title and subtitle">
                                    <x-mary-icon name="o-pencil" class="h-4 w-4" />
                                </button>
                            @endif
                        </div>
                        <p class="mt-2 max-w-2xl text-sm leading-6 {{ $dashboard->description !== null ? 'text-base-content/55' : 'text-base-content/35' }}">{{ $dashboard->description ?? 'No description — click the pencil to add one.' }}</p>
                    </div>
                @endif
            @else
                <div class="min-w-0">
                    <p class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.18em] text-primary">
                        <span class="inline-block h-px w-6 bg-primary/60"></span>
                        Product Database
                    </p>
                    <h1 class="font-display text-3xl font-semibold tracking-tight text-base-content sm:text-4xl">Home</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-base-content/55">The installed base at a glance — equipment, warranty and contract posture, fleet state, and installation momentum, computed live from the imported Product Database.</p>
                </div>
            @endif
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" onclick="window.print()" class="admin-secondary-button no-print">Print report</button>
                <a href="{{ route('installed-products') }}" wire:navigate class="admin-primary-button no-print">Open Product Database</a>
            </div>
        </div>
        <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-base-300 pt-4">
            <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] text-base-content/50">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 1 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/></svg>
                Scope
            </div>
            <select wire:model.live="region" class="admin-control min-w-[170px]" aria-label="Filter by region" @if ($regionLocked) disabled title="Your role is scoped to {{ $region }}" @endif>
                @foreach ($regionOptions as $option)
                    <option @if ($regionLocked && $option !== $region) hidden @endif>{{ $option }}</option>
                @endforeach
            </select>
            @if ($regionLocked)
                <span class="rounded-full bg-info/10 px-3 py-1 text-[11px] font-bold text-info">Scoped to your region</span>
            @endif
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
                <span>Product data as of <span class="font-semibold text-base-content/70">{{ $freshness }}</span></span>
            </div>
        </div>
    </header>

    <x-admin.filter-bar>
        <select wire:model.live="branchFilter" class="admin-control" aria-label="Filter by branch">
            @foreach ($branchOptions as $branch)
                <option>{{ $branch }}</option>
            @endforeach
        </select>
        <select wire:model.live="statusFilter" class="admin-control" aria-label="Filter by status">
            @foreach ($statusOptions as $status)
                <option>{{ $status }}</option>
            @endforeach
        </select>
        <input type="date" wire:model.live="dateFrom" class="admin-control" aria-label="From date">
        <input type="date" wire:model.live="dateTo" class="admin-control" aria-label="To date">
        @if ($hasActiveFilters)
            <button type="button" wire:click="clearFilters" class="admin-secondary-button">
                <x-mary-icon name="o-x-mark" class="h-4 w-4" />
                Clear filters ({{ count($activeFilters) }})
            </button>
        @endif
    </x-admin.filter-bar>

    @if ($hasActiveFilters)
        <div class="flex flex-wrap items-center gap-2" role="status" aria-label="Active filters">
            <span class="text-[11px] font-bold uppercase tracking-[0.1em] text-primary">Active filters:</span>
            @if (!empty($activeFilters['branch']))
                <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">Branch: {{ $activeFilters['branch'] }}</span>
            @endif
            @if (!empty($activeFilters['status']))
                <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">Status: {{ $activeFilters['status'] }}</span>
            @endif
            @if (!empty($activeFilters['from']))
                <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">From: {{ $activeFilters['from'] }}</span>
            @endif
            @if (!empty($activeFilters['to']))
                <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">To: {{ $activeFilters['to'] }}</span>
            @endif
        </div>
    @endif

    {{-- Grid layout engine: registry-driven, expression-powered widget
        grid, customizable per user. Sits between the header and the
        hardcoded overview sections. --}}
    <section aria-label="Operations grid">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-primary">
                    <span class="inline-block h-px w-6 bg-primary/60"></span>
                    Operations grid
                </p>
                <p class="mt-1 text-xs text-base-content/50">
                    @if ($dashboard)
                        <span class="font-semibold text-base-content/70">{{ $dashboard->name }}</span>
                        &middot; {{ $canEditDashboard ? 'you can edit' : 'view only' }}
                        &middot;
                    @endif
                    Layout-engine widgets — every value computed live from the imported installed base.
                </p>
            </div>
            <div class="no-print flex flex-wrap items-center gap-2">
                @if ($customizing)
                    <span class="hidden text-[11px] font-semibold text-base-content/45 sm:inline">Drag to reorder · corner to resize · gear to configure — saved on Done</span>
                    @if ($allowAddWidgets)
                        <button type="button" x-on:click="$dispatch('open-modal', { name: 'add-widget' })" class="admin-secondary-button">Add widget</button>
                    @endif
                    <button type="button" wire:click="resetLayout" wire:confirm="Reset this dashboard to the default layout? Unsaved changes are lost." class="admin-secondary-button">Reset layout</button>
                    <button type="button" wire:click="toggleCustomizing" class="admin-secondary-button">Cancel</button>
                    <button type="button" data-grid-done="default" class="admin-primary-button">Done</button>
                @else
                    {{-- Core (system) rows: shared with everyone already — no
                        per-person sharing, and their data vocabulary is the
                        page's, not connectable sources. --}}
                    @if ($dashboard && ! $dashboard->is_system)
                        <button type="button" x-on:click="$dispatch('open-modal', { name: 'dashboard-sources' })" class="admin-secondary-button">
                            <x-mary-icon name="o-circle-stack" class="h-4 w-4" />
                            Data sources ({{ $sources->count() }})
                        </button>
                        @if ($canEditDashboard)
                            <a href="{{ route('visualize', ['dashboard' => $dashboard->id]) }}" class="admin-secondary-button" title="Create a chart or number widget for this dashboard">
                                <x-mary-icon name="o-chart-bar" class="h-4 w-4" />
                                Visualize
                            </a>
                            <button type="button" x-on:click="$dispatch('open-modal', { name: 'dashboard-share' })" class="admin-secondary-button">
                                <x-mary-icon name="o-user-plus" class="h-4 w-4" />
                                Share
                            </button>
                        @endif
                    @endif
                    @if ($canEditDashboard)
                        <button type="button" wire:click="toggleCustomizing" class="admin-secondary-button">Customize grid</button>
                    @endif
                @endif
            </div>
        </div>
        <x-dashboard.grid :widgets="$grid['widgets']" :editing="$customizing" grid-key="default" />
    </section>

    {{-- Widget settings: opened per-widget from the gear button in edit
        mode. Fields are driven by the factory's settings schema; expression
        fields are syntax-validated before Apply. --}}
    <x-admin.modal name="widget-settings" title="Widget settings" description="Configure this widget. Changes apply to the draft immediately and are saved when you click Done." size="lg">
        <div class="space-y-4">
            @if ($settingsError)
                <p class="rounded-md bg-error/10 px-3 py-2 text-xs font-semibold text-error" role="alert">{{ $settingsError }}</p>
            @endif
            @if ($settingsApplied)
                <p class="rounded-md bg-success/10 px-3 py-2 text-xs font-semibold text-success" role="status">
                    Applied to the draft ✓ — {{ $settingsGraphCount }} formula canvas{{ $settingsGraphCount === 1 ? '' : 'es' }} received from the editor. Keep editing, or close and click Done to save.
                </p>
            @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($settingsSchema as $field)
                    @php $fieldKey = 'settingsProps.'.$field['key']; @endphp
                    <div class="{{ ($field['type'] ?? '') === 'expression' ? 'sm:col-span-2' : '' }}">
                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-[0.1em] text-base-content/55" for="widget-field-{{ $field['key'] }}">
                            {{ $field['label'] }} @if ($field['required'] ?? false)<span class="text-error" title="Required">*</span>@endif
                        </label>
                        @if (($field['type'] ?? '') === 'select')
                            <select id="widget-field-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="admin-control w-full">
                                @foreach ($field['options'] ?? [] as $option)
                                    <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                                @endforeach
                            </select>
                        @elseif (($field['type'] ?? '') === 'metric')
                            <select id="widget-field-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="admin-control w-full">
                                @if (isset($field['grouped_options']))
                                    @foreach ($field['grouped_options'] as $group => $grouped)
                                        <optgroup label="{{ $group }}">
                                            @foreach ($grouped as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                @else
                                    @foreach (($field['options'] ?? array_keys($metricLabels)) as $option)
                                        <option value="{{ $option }}">{{ $metricOptions[$option] ?? $metricLabels[$option] ?? ucfirst($option) }}</option>
                                    @endforeach
                                @endif
                            </select>
                        @elseif (($field['type'] ?? '') === 'dataset')
                            <select id="widget-field-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="admin-control w-full">
                                @if (isset($field['grouped_options']))
                                    @foreach ($field['grouped_options'] as $group => $grouped)
                                        <optgroup label="{{ $group }}">
                                            @foreach ($grouped as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                @else
                                    @foreach (($field['options'] ?? array_keys($datasetOptions)) as $option)
                                        <option value="{{ $option }}">{{ $datasetOptions[$option] ?? ucfirst($option) }}</option>
                                    @endforeach
                                @endif
                            </select>
                        @elseif (($field['type'] ?? '') === 'boolean')
                            <label class="flex cursor-pointer items-center gap-2 text-sm">
                                <input type="checkbox" id="widget-field-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="toggle toggle-sm toggle-primary">
                                <span class="text-base-content/70">{{ $field['toggle_label'] ?? 'Enabled' }}</span>
                            </label>
                        @elseif (($field['type'] ?? '') === 'number')
                            <input type="number" id="widget-field-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="admin-control w-full" placeholder="{{ $field['placeholder'] ?? '' }}">
                        @elseif (($field['type'] ?? '') === 'expression' && ($field['visual'] ?? false))
                            {{-- Drag-drop expression tree (no syntax typing). --}}
                            <x-dashboard.expression-tree
                                :field-key="$fieldKey"
                                :expression="$settingsProps[$field['key']] ?? ''"
                                :tree="$field['tree'] ?? null"
                                :graph="$settingsProps[$field['key'].'_tree'] ?? null"
                                :graph-key="$fieldKey.'_tree'"
                                :metric-labels="$metricLabels"
                                :metric-values="$metricValues"
                                :widget-id="$settingsWidgetId"
                                :open-count="$settingsOpenCount"
                            />
                        @elseif (($field['type'] ?? '') === 'expression')
                            <textarea id="widget-field-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" rows="2" class="admin-control w-full font-mono text-xs" placeholder="{{ $field['placeholder'] ?? '' }}"></textarea>
                        @else
                            <input type="text" id="widget-field-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="admin-control w-full" placeholder="{{ $field['placeholder'] ?? '' }}">
                        @endif
                        @if ($field['help'] ?? false)
                            <p class="mt-1 text-[11px] leading-4 text-base-content/45">{{ $field['help'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            {{-- Guidance for an empty widget: pick data above, or connect a
                table under Data sources. Nothing is connected automatically. --}}
            @php
                $settingsNeedData = collect($settingsSchema)->contains(fn ($f) => in_array($f['type'] ?? '', ['metric', 'dataset'], true));
                $settingsHasData = filled($settingsProps['metric'] ?? null) || filled($settingsProps['dataset'] ?? null) || filled(trim((string) ($settingsProps['formula'] ?? '')));
            @endphp
            @if ($settingsNeedData && ! $settingsHasData)
                <p class="rounded-md bg-warning/10 px-3 py-2 text-xs font-semibold text-warning" role="status">
                    No data selected — pick a value above, or connect a table under Data sources. Nothing is connected automatically.
                </p>
            @endif
            {{-- Sticky action row: stays visible at the bottom of the
                modal's scroll area on any screen size. Apply keeps the
                modal open so the canvas blocks stay editable, and passes
                the canvas graphs from the editors' in-memory registry —
                they travel INSIDE this request, so nothing can be lost
                to a late or superseded sync. --}}
            @php $buildMark = substr(md5_file(public_path('build/manifest.json')), 0, 6) @endphp
            <div class="sticky bottom-0 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-base-300 bg-base-100 px-4 py-3 sm:-mx-5 sm:px-5">
                <span class="mr-auto text-[10px] font-mono text-base-content/25">build {{ $buildMark }}</span>
                <button type="button" wire:click="cancelWidgetSettings" x-on:click="$dispatch('close-modal', { name: 'widget-settings' })" class="admin-secondary-button">Close</button>
                <button type="button" x-on:click="$wire.applyWidgetSettings(window.__treeGraphs || {})" class="admin-primary-button">Apply</button>
            </div>
        </div>
    </x-admin.modal>

    {{-- Add widget: the widget registry, provided by WidgetRegistry::definitions().
        Opt-in via config dashboard.allow_add_widgets. --}}
    @if ($allowAddWidgets)
        <x-admin.modal name="add-widget" title="Add widget" description="Pick a preset or a widget type — it lands at the end of the grid with settings you can tune from its gear icon." size="lg">
            @if (! empty($widgetPresets))
                <p class="mb-2 text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Presets — ready-made widgets for this page</p>
                <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($widgetPresets as $key => $preset)
                        <button type="button" wire:click="addPreset('{{ $key }}')" class="group flex flex-col items-start gap-1 rounded-lg border border-primary/30 bg-primary/5 p-4 text-left transition duration-150 hover:-translate-y-0.5 hover:border-primary/60 hover:shadow-lg hover:shadow-primary/10">
                            <span class="text-sm font-bold text-base-content transition-colors group-hover:text-primary">{{ $preset['title'] }}</span>
                            <span class="text-xs leading-5 text-base-content/55">{{ $preset['description'] }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="mb-2 text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Widget types — blank canvas</p>
            @endif
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($widgetDefinitions as $type => $definition)
                    <button type="button" wire:click="addWidget('{{ $type }}')" class="group flex flex-col items-start gap-1 rounded-lg border border-base-300 bg-base-100 p-4 text-left transition duration-150 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10">
                        <span class="text-sm font-bold text-base-content transition-colors group-hover:text-primary">{{ $definition['title'] }}</span>
                        <span class="text-xs leading-5 text-base-content/55">{{ $definition['description'] }}</span>
                    </button>
                @endforeach
            </div>
            @if ($dashboard && ! $dashboard->is_system && ($sources ?? collect())->isEmpty())
                <div class="mt-3 flex flex-wrap items-center gap-2 rounded-md border border-base-300 bg-base-200/50 px-3 py-2 text-xs text-base-content/65">
                    <span>No tables connected — new widgets read the Product Database until you connect one.</span>
                    <button type="button" x-on:click="$dispatch('close-modal', { name: 'add-widget' }); $dispatch('open-modal', { name: 'dashboard-sources' })" class="font-bold text-primary hover:underline">Connect a table</button>
                </div>
            @endif
        </x-admin.modal>
    @endif

    {{-- Core (system) rows skip the per-dashboard source/share UI: they are
         shared with everyone by definition and read the page vocabulary. --}}
    @if ($dashboard && ! $dashboard->is_system)
        {{-- Data sources: which tables this dashboard reads from. Widgets
             reference a source by its alias. --}}
        <x-admin.modal name="dashboard-sources" title="Data sources" description="Tables this dashboard reads from. Each source gets an alias widgets reference (e.g. pdb.brands)." size="lg">
            <div class="space-y-4">
                <div class="divide-y divide-base-300 rounded-md border border-base-300">
                    @forelse ($sources as $source)
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-base-content">
                                    {{ collect($tableOptions)->firstWhere('key', $source->table_key)['label'] ?? $source->table_key }}
                                </p>
                                <p class="font-mono text-[11px] text-base-content/45">{{ $source->alias }}</p>
                            </div>
                            @if ($canEditDashboard)
                                <button type="button" wire:click="removeSource({{ $source->id }})" wire:confirm="Disconnect this source? Widgets referencing it will show an error card until reconnected." class="admin-icon-button" aria-label="Disconnect source">
                                    <x-mary-icon name="o-trash" class="h-4 w-4" />
                                </button>
                            @endif
                        </div>
                    @empty
                        <p class="px-4 py-6 text-center text-sm text-base-content/55">No sources connected yet.</p>
                    @endforelse
                </div>

                @if ($canEditDashboard)
                    <form wire:submit="connectSource" class="flex flex-wrap items-end gap-3 rounded-md border border-base-300 p-3">
                        <div class="min-w-[220px] flex-1">
                            <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Table</label>
                            <select wire:model="sourceTableKey" class="admin-control w-full">
                                <option value="">-- choose a table --</option>
                                @foreach ($tableOptions as $option)
                                    <option value="{{ $option['key'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('sourceTableKey')" class="mt-1.5" />
                        </div>
                        <div class="w-40">
                            <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Alias (optional)</label>
                            <input type="text" wire:model="sourceAlias" class="admin-control w-full font-mono text-xs" placeholder="auto">
                            <x-input-error :messages="$errors->get('sourceAlias')" class="mt-1.5" />
                        </div>
                        <button type="submit" class="admin-primary-button">
                            <x-mary-icon name="o-link" class="h-4 w-4" />
                            Connect table
                        </button>
                    </form>
                @endif
            </div>
        </x-admin.modal>

        {{-- People sharing: view or edit access per person. --}}
        <x-admin.modal name="dashboard-share" title="Share dashboard" description="Give specific people access. Viewers can open the dashboard; editors can also customize the grid and its sources." size="lg">
            <div class="space-y-4">
                <div class="divide-y divide-base-300 rounded-md border border-base-300">
                    @forelse ($shares as $share)
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-base-content">{{ $share->user?->name }}</p>
                                <p class="truncate text-xs text-base-content/50">{{ $share->user?->email }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="rounded-full px-3 py-1 text-[11px] font-bold uppercase tracking-[0.08em] {{ $share->permission === 'edit' ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/60' }}">
                                    {{ $share->permission }}
                                </span>
                                <button type="button" wire:click="unshareDashboard({{ $share->id }})" wire:confirm="Remove this person's access?" class="admin-icon-button" aria-label="Remove access">
                                    <x-mary-icon name="o-x-mark" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    @empty
                        <p class="px-4 py-6 text-center text-sm text-base-content/55">Only you can see this dashboard.</p>
                    @endforelse
                </div>

                <form wire:submit="shareDashboard" class="flex flex-wrap items-end gap-3 rounded-md border border-base-300 p-3">
                    <div class="min-w-[240px] flex-1">
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Person</label>
                        <select wire:model="shareUserId" class="admin-control w-full">
                            <option value="">-- choose a person --</option>
                            @foreach ($userOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }} ({{ $option->email }})</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('shareUserId')" class="mt-1.5" />
                    </div>
                    <div class="w-36">
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Access</label>
                        <select wire:model="sharePermission" class="admin-control w-full">
                            <option value="view">Can view</option>
                            <option value="edit">Can edit</option>
                        </select>
                    </div>
                    <button type="submit" class="admin-primary-button">
                        <x-mary-icon name="o-user-plus" class="h-4 w-4" />
                        Share
                    </button>
                </form>
            </div>
        </x-admin.modal>
    @endif

    @if (! $dashboard || $dashboard->is_system)
    <footer class="flex flex-col gap-2 px-1 text-[11px] text-base-content/35 sm:flex-row sm:items-center sm:justify-between">
        <span>Product Database overview · click any card, segment, or point to open the records behind it — the grid arrives pre-filtered, with a one-click clear.</span>
        <span>All metrics computed live from the imported installed-base records — never hardcoded.</span>
    </footer>
    @else
    <footer class="px-1 text-[11px] text-base-content/35">
        Dashboard widgets compute live from the connected data sources — never hardcoded.
    </footer>
    @endif
</div>
