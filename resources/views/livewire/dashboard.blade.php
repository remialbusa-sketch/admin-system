@php
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
    $signalBorders = [
        'danger' => 'hover:border-error/50 hover:shadow-lg hover:shadow-error/10',
        'warning' => 'hover:border-warning/60 hover:shadow-lg hover:shadow-warning/10',
        'success' => 'hover:border-success/50 hover:shadow-lg hover:shadow-success/10',
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

    // Brand donut segments arrive from the service with their own unique
    // categorical colors (ChartPalette) — Others is neutral.
    $brandSegments = $brandDonut;
@endphp

<div class="mx-auto w-full max-w-none space-y-8 pb-4">
    <header class="border-b border-base-300 pb-6 pt-2">
        <div class="flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
            @if ($dashboard)
                @if ($editingHeader)
                    <div class="min-w-0 flex-1 space-y-3">
                        <input type="text" wire:model="editingName" class="admin-control w-full text-3xl font-semibold tracking-tight sm:text-4xl" placeholder="Dashboard title" autofocus>
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

    @if (($isEmptyHome ?? false))
        <div class="admin-surface p-8 text-center space-y-4">
            <h2 class="text-lg font-bold text-base-content">No dashboard yet</h2>
            <p class="text-sm text-base-content/60">Create a table, import your data, then create a dashboard for that table. Your dashboard will start empty — add widgets for your table.</p>
            <div class="flex justify-center gap-2">
                <a href="{{ route('tables') }}" wire:navigate class="admin-secondary-button">Go to Tables</a>
                <a href="{{ route('dashboards.index') }}" wire:navigate class="admin-primary-button">Create dashboard</a>
            </div>
        </div>
    @endif

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
                    <button type="button" data-grid-done class="admin-primary-button">Done</button>
                @else
                    @if ($dashboard)
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
        <x-dashboard.grid :widgets="$grid['widgets']" :editing="$customizing" />
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
        <x-admin.modal name="add-widget" title="Add widget" description="Pick a widget type — it lands at the end of the grid with default settings you can tune from its gear icon." size="lg">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($widgetDefinitions as $type => $definition)
                    <button type="button" wire:click="addWidget('{{ $type }}')" class="group flex flex-col items-start gap-1 rounded-lg border border-base-300 bg-base-100 p-4 text-left transition duration-150 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10">
                        <span class="text-sm font-bold text-base-content transition-colors group-hover:text-primary">{{ $definition['title'] }}</span>
                        <span class="text-xs leading-5 text-base-content/55">{{ $definition['description'] }}</span>
                    </button>
                @endforeach
            </div>
            @if ($dashboard && ($sources ?? collect())->isEmpty())
                <div class="mt-3 flex flex-wrap items-center gap-2 rounded-md border border-base-300 bg-base-200/50 px-3 py-2 text-xs text-base-content/65">
                    <span>No tables connected — new widgets read the Product Database until you connect one.</span>
                    <button type="button" x-on:click="$dispatch('close-modal', { name: 'add-widget' }); $dispatch('open-modal', { name: 'dashboard-sources' })" class="font-bold text-primary hover:underline">Connect a table</button>
                </div>
            @endif
        </x-admin.modal>
    @endif

    @if ($dashboard)
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

    {{-- The curated Product Database overview is the Home dashboard only.
         User-created / shared dashboards render just the widget grid. --}}
    @if (! $dashboard)
    @php
        $headlineKeys = ['Installed products', 'Active products', 'Warranty covered', 'Service contracts', 'Annual BU charges'];
        $primary = collect($kpis)->filter(fn ($k) => in_array($k['label'], $headlineKeys))->values()->all();
        $secondary = collect($kpis)->filter(fn ($k) => ! in_array($k['label'], $headlineKeys))->values()->all();
        $lead = $primary[0] ?? null;
        $rest = array_slice($primary, 1);
    @endphp
    <section aria-label="Headline metrics" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        @if ($lead)
        <div class="admin-surface relative overflow-hidden p-6 transition duration-200 hover:border-primary/40 sm:p-8 xl:col-span-1">
            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-primary/[0.07] blur-2xl"></div>
            <div class="relative">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-base-content/50">{{ $lead['label'] }}</p>
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-success/15 text-[10px] font-black text-success" title="{{ $ragLabel[$lead['rag']] }}">{{ $ragGlyph[$lead['rag']] }}</span>
                </div>
                <p class="mt-5 font-display text-6xl font-semibold leading-none tracking-tight tabular-nums text-base-content sm:text-7xl">{{ number_format($lead['value'], $lead['decimals'] ?? 0) }}</p>
                <p class="mt-2 text-xs uppercase tracking-[0.1em] text-base-content/40">units installed</p>
                <div class="mt-6 flex flex-wrap gap-2">
                    <span class="rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">{{ $lead['context'] }}</span>
                    @if ($lead['href'] ?? null)
                        <a href="{{ $lead['href'] }}" wire:navigate class="inline-flex items-center gap-1 text-[11px] font-semibold text-base-content/50 underline-offset-2 hover:text-primary hover:underline">Open table <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5" /></a>
                    @endif
                </div>
            </div>
        </div>
        @endif
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:col-span-2">
            @foreach ($rest as $kpi)
                @php $kpiHref = $kpi['href'] ?? null; $kpiTag = $kpiHref ? 'a' : 'div'; @endphp
                <{{ $kpiTag }} @if ($kpiHref) href="{{ $kpiHref }}" wire:navigate @endif
                     class="admin-surface group flex flex-col justify-between p-6 {{ $kpiHref ? 'cursor-pointer transition duration-200 hover:-translate-y-1 hover:bg-base-200/40 hover:shadow-lg hover:shadow-primary/10' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 truncate text-[11px] font-bold uppercase tracking-[0.14em] text-base-content/45">{{ $kpi['label'] }}</p>
                        <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full {{ $ragBg[$kpi['rag']] }} text-[9px] font-black text-base-100" title="{{ $ragLabel[$kpi['rag']] }}">{{ $ragGlyph[$kpi['rag']] }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-baseline gap-x-2">
                        <span class="font-display text-4xl font-semibold leading-none tracking-tight tabular-nums">{{ number_format($kpi['value'], $kpi['decimals'] ?? 0) }}{{ $kpi['suffix'] ?? '' }}</span>
                    </div>
                    <div class="mt-3 flex items-end justify-between gap-2">
                        <p class="truncate text-[11px] text-base-content/40">{{ $kpi['context'] }}</p>
                    </div>
                </{{ $kpiTag }}>
            @endforeach
        </div>
    </section>

    <section aria-label="Supporting metrics" class="admin-surface grid grid-cols-1 divide-y divide-base-300 sm:grid-cols-3 sm:divide-y-0 sm:divide-x">
        @foreach ($secondary as $kpi)
            @php $kpiHref = $kpi['href'] ?? null; $kpiTag = $kpiHref ? 'a' : 'div'; @endphp
            <{{ $kpiTag }} @if ($kpiHref) href="{{ $kpiHref }}" wire:navigate @endif
                 class="group flex items-center gap-4 px-6 py-4 {{ $kpiHref ? 'cursor-pointer transition hover:bg-base-200/50' : '' }}">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $ragBg[$kpi['rag']] }}/15 text-sm font-black {{ $ragText[$kpi['rag']] }}" aria-hidden="true">{{ $ragGlyph[$kpi['rag']] }}</div>
                <div class="min-w-0">
                    <p class="truncate text-[11px] font-bold uppercase tracking-[0.12em] text-base-content/45">{{ $kpi['label'] }}</p>
                    <p class="mt-1 font-display text-xl font-semibold leading-none tabular-nums">{{ number_format($kpi['value'], $kpi['decimals'] ?? 0) }}{{ $kpi['suffix'] ?? '' }}</p>
                    <p class="mt-1 truncate text-[10px] text-base-content/35">{{ $kpi['context'] }}</p>
                </div>
            </{{ $kpiTag }}>
        @endforeach
    </section>

    <section aria-label="Regional position" class="admin-surface p-6 sm:p-7">
        <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">01 · Regions</p>
        <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Regional position</h2>
        <p class="mt-1 text-xs text-base-content/50">Installed base per region — the dot reflects the active share</p>
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($regions as $row)
                @php
                    $activeRatio = $row['products'] > 0 ? round(($row['active'] / $row['products']) * 100) : 0;
                    $rag = $activeRatio >= 90 ? 'green' : ($activeRatio >= 75 ? 'amber' : 'red');
                    $share = $regionMax['products'] > 0 ? (int) round(($row['products'] / $regionMax['products']) * 100) : 0;
                @endphp
                <a href="{{ $row['href'] }}" wire:navigate class="group flex flex-col gap-3 rounded-xl border border-base-300 bg-base-100 p-4 transition duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10" title="View the {{ number_format($row['products']) }} installed products in {{ $row['region'] }}">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-bold text-base-content">{{ $row['region'] }}</p>
                        <span class="inline-flex h-4 w-4 items-center justify-center rounded-full {{ $ragBg[$rag] }} text-[9px] font-black text-base-100" title="{{ $ragLabel[$rag] }} · {{ $activeRatio }}% active">{{ $ragGlyph[$rag] }}</span>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div>
                            <p class="font-display text-lg font-semibold tabular-nums">{{ number_format($row['products']) }}</p>
                            <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-base-content/40">installed</p>
                        </div>
                        <div>
                            <p class="font-display text-lg font-semibold tabular-nums">{{ number_format($row['active']) }}</p>
                            <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-base-content/40">active</p>
                        </div>
                        <div>
                            <p class="font-display text-lg font-semibold tabular-nums">{{ number_format($row['warranty']) }}</p>
                            <p class="text-[9px] font-bold uppercase tracking-[0.1em] text-base-content/40">warranty</p>
                        </div>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-base-200">
                        <div class="h-full rounded-full bg-primary/70" style="width: {{ max(4, $share) }}%"></div>
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    <section aria-label="Fleet state and installation trend" class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.35fr)]">
        <div class="admin-surface flex h-full flex-col p-6 sm:p-7" x-data="{ active: null }">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">02 · Fleet state</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Device status</h2>
            <p class="mt-1 text-xs text-base-content/50">Hover to focus · click a segment or row to open the records</p>
            <div class="mt-7 flex flex-1 flex-col items-center justify-center gap-7">
                <div class="relative h-40 w-40 shrink-0">
                    <svg viewBox="0 0 42 42" class="h-40 w-40 -rotate-90" role="img" aria-label="Device status mix">
                        @php $offset = 0; $circ = 2 * pi() * 15.9155; @endphp
                        @foreach ($fleetDonut as $i => $seg)
                            @php
                                $frac = $fleetTotal > 0 ? $seg['value'] / $fleetTotal : 0;
                                $len = $frac * $circ;
                            @endphp
                            @if ($seg['href'])
                                <a href="{{ $seg['href'] }}" wire:navigate x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null"
                                    class="cursor-pointer" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .3' : ''">
                                    <title>{{ $seg['label'] }} — view the {{ number_format($seg['value']) }} matching records</title>
                                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $chartColors[$seg['tone']] }}" stroke-width="6"
                                        stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"
                                        style="transition: stroke-width .2s ease" :style="active === {{ $i }} ? 'stroke-width: 9' : ''"></circle>
                                </a>
                            @else
                                <g x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .3' : ''">
                                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $chartColors[$seg['tone']] }}" stroke-width="6"
                                        stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"></circle>
                                </g>
                            @endif
                            @php $offset += $len; @endphp
                        @endforeach
                        <circle cx="21" cy="21" r="12.9" fill="var(--color-base-100)" />
                    </svg>
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-display text-2xl font-semibold tabular-nums">{{ number_format($fleetTotal) }}</span>
                        <span class="text-[9px] font-bold uppercase tracking-[0.14em] text-base-content/40">units</span>
                    </div>
                </div>
                <ul class="w-full min-w-0 space-y-3">
                    @foreach ($fleetDonut as $i => $seg)
                        <li x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .45' : ''">
                            <a @if ($seg['href']) href="{{ $seg['href'] }}" wire:navigate @endif class="flex items-center gap-3 text-sm {{ $seg['href'] ? 'cursor-pointer transition-colors hover:text-primary' : '' }}" @if ($seg['href']) title="View the {{ number_format($seg['value']) }} {{ strtolower($seg['label']) }} records" @endif>
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $toneFill[$seg['tone']] }} transition-transform duration-200" :style="active === {{ $i }} ? 'transform: scale(1.5)' : ''"></span>
                                <span class="min-w-0 flex-1 truncate text-base-content/65">{{ $seg['label'] }}</span>
                                <span class="font-semibold tabular-nums">{{ number_format($seg['value']) }}</span>
                                <span class="w-12 text-right text-[11px] tabular-nums text-base-content/35">{{ round(($seg['value'] / $fleetTotal) * 100) }}%</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="admin-surface flex flex-col p-6 sm:p-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">03 · Installation trend</p>
                    <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Installations</h2>
                    <p class="mt-1 text-xs text-base-content/50">Units installed by month · last {{ $trendMonths }} months</p>
                </div>
                @if ($installDelta !== null)
                    <span class="inline-flex shrink-0 items-center gap-2 self-start rounded-full px-3 py-1.5 text-xs font-bold {{ $installDelta >= 0 ? 'bg-success/10 text-success' : 'bg-error/10 text-error' }}" title="Installations in this window vs the equal window before it">
                        <span class="text-[10px] opacity-70">vs prev</span>
                        {{ $installDelta >= 0 ? '▲' : '▼' }} {{ abs($installDelta) }}%
                    </span>
                @endif
            </div>
            @if (!empty($installArea['line']))
                <div class="mt-7 flex flex-1 flex-col justify-center">
                    <svg viewBox="0 0 640 140" class="install-trend h-52 w-full" role="img" aria-label="Installations per month">
                        <defs>
                            <linearGradient id="installFade" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="var(--color-primary)" stop-opacity="0.18" />
                                <stop offset="100%" stop-color="var(--color-primary)" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <path d="{{ $installArea['area'] }}" fill="url(#installFade)" />
                        <path d="{{ $installArea['line'] }}" fill="none" stroke="var(--color-primary)" stroke-width="2.5" vector-effect="non-scaling-stroke" />
                        @foreach ($installArea['dots'] as $i => $dot)
                            <a href="{{ $installTrend[$i]['href'] }}" wire:navigate class="cursor-pointer">
                                <title>{{ $installTrend[$i]['label'] }} · {{ number_format($installTrend[$i]['count']) }} installed — click to view the records</title>
                                <circle class="trend-halo" cx="{{ $dot[0] }}" cy="{{ $dot[1] }}" r="11" fill="var(--color-primary)" />
                                <circle cx="{{ $dot[0] }}" cy="{{ $dot[1] }}" r="10" fill="transparent" />
                                <circle class="trend-dot" cx="{{ $dot[0] }}" cy="{{ $dot[1] }}" r="3" fill="var(--color-primary)" />
                            </a>
                        @endforeach
                    </svg>
                    <div class="mt-2 flex justify-between text-[10px] font-semibold uppercase tracking-wide text-base-content/35">
                        <span>{{ $installTrend[0]['short'] }}</span>
                        <span>{{ $installTrend[intdiv(count($installTrend), 2)]['short'] }}</span>
                        <span>{{ $installTrend[count($installTrend) - 1]['short'] }}</span>
                    </div>
                </div>
            @else
                <div class="mt-6 rounded-lg border border-dashed border-base-300 p-6 text-center">
                    <p class="text-sm font-semibold text-base-content/70">Not enough installation data yet</p>
                    <p class="mt-1 text-xs text-base-content/45">Two or more months of dated installations are needed to draw the trend.</p>
                </div>
            @endif
        </div>
    </section>

    <section aria-label="Base composition" class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
        <div class="admin-surface flex h-full flex-col p-6 sm:p-7" x-data="{ active: null }">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">04 · Base composition</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Leading installed brands</h2>
            <p class="mt-1 text-xs text-base-content/50">Hover to focus · click a segment or row to open the records</p>
            <div class="my-auto mt-7 flex flex-col items-center gap-8 lg:flex-row lg:items-center">
                <div class="relative h-40 w-40 shrink-0">
                    <svg viewBox="0 0 42 42" class="h-40 w-40 -rotate-90" role="img" aria-label="Leading installed brands">
                        @php $offset = 0; $circ = 2 * pi() * 15.9155; @endphp
                        @foreach ($brandSegments as $i => $seg)
                            @php $len = ($seg['value'] / $brandTotal) * $circ; @endphp
                            @if ($seg['href'])
                                <a href="{{ $seg['href'] }}" wire:navigate x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null"
                                    class="cursor-pointer" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .3' : ''">
                                    <title>{{ $seg['label'] }} — view the {{ number_format($seg['value']) }} {{ $seg['label'] }} records</title>
                                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $seg['color'] }}" stroke-width="6"
                                        stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"
                                        style="transition: stroke-width .2s ease" :style="active === {{ $i }} ? 'stroke-width: 9' : ''"></circle>
                                </a>
                            @else
                                <g x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .3' : ''">
                                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $seg['color'] }}" stroke-width="6"
                                        stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"></circle>
                                </g>
                            @endif
                            @php $offset += $len; @endphp
                        @endforeach
                        <circle cx="21" cy="21" r="12.9" fill="var(--color-base-100)" />
                    </svg>
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-display text-2xl font-semibold tabular-nums">{{ number_format($brandTotal) }}</span>
                        <span class="text-[9px] font-bold uppercase tracking-[0.14em] text-base-content/40">units</span>
                    </div>
                </div>
                <ul class="w-full min-w-0 flex-1 space-y-2.5">
                    @forelse ($brandSegments as $i => $seg)
                        <li x-on:mouseenter="active = {{ $i }}" x-on:mouseleave="active = null" style="transition: opacity .2s ease" :style="active !== null && active !== {{ $i }} ? 'opacity: .45' : ''">
                            <a @if ($seg['href']) href="{{ $seg['href'] }}" wire:navigate @endif class="flex items-center gap-2.5 text-sm {{ $seg['href'] ? 'cursor-pointer transition-colors hover:text-primary' : '' }}" @if ($seg['href']) title="View the {{ number_format($seg['value']) }} {{ $seg['label'] }} records" @endif>
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full transition-transform duration-200" style="background: {{ $seg['color'] }}" :style="active === {{ $i }} ? 'transform: scale(1.5)' : ''"></span>
                                <span class="min-w-0 flex-1 truncate text-base-content/65">{{ $seg['label'] }}</span>
                                <span class="font-semibold tabular-nums">{{ number_format($seg['value']) }}</span>
                                <span class="w-11 text-right text-[11px] tabular-nums text-base-content/35">{{ round(($seg['value'] / $brandTotal) * 100) }}%</span>
                            </a>
                        </li>
                    @empty
                        <li class="text-sm text-base-content/45">No Product Database data imported.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="admin-surface p-6 sm:p-7">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">05 · Machine mix</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Equipment types</h2>
            <p class="mt-1 text-xs text-base-content/50">Installed units by machine type</p>
            <div class="mt-6 space-y-3">
                @forelse ($machineTypes as $i => $row)
                    @php $typeHref = $row['href'] ?? null; $typeTag = $typeHref ? 'a' : 'div'; @endphp
                    <{{ $typeTag }} @if ($typeHref) href="{{ $typeHref }}" wire:navigate title="View the {{ number_format($row['total']) }} {{ $row['label'] }} records" @endif
                         class="group flex items-center gap-3 rounded-md transition duration-200 {{ $typeHref ? 'cursor-pointer hover:bg-base-200/50' : '' }}">
                        <span class="w-5 shrink-0 text-right text-[11px] font-black tabular-nums text-base-content/30">{{ $i + 1 }}</span>
                        <span class="w-44 shrink-0 truncate text-sm font-medium text-base-content/80" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                        <div class="h-6 flex-1 overflow-hidden rounded-full bg-base-200/80">
                            <div class="h-full rounded-full bg-gradient-to-r from-primary to-primary/60 transition-all duration-200 group-hover:to-primary" style="width: {{ round(($row['total'] / $typeMax) * 100) }}%"></div>
                        </div>
                        <span class="w-10 shrink-0 text-right text-xs font-bold tabular-nums text-base-content/70">{{ number_format($row['total']) }}</span>
                        <x-mary-icon name="o-chevron-right" class="h-3.5 w-3.5 shrink-0 -translate-x-1 text-primary opacity-0 transition-all duration-200 group-hover:translate-x-0 group-hover:opacity-100" />
                    </{{ $typeTag }}>
                @empty
                    <p class="text-sm text-base-content/45">No machine-type data available.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section aria-label="Top accounts" class="admin-surface flex h-full flex-col overflow-hidden">
        <div class="border-b border-base-300 px-6 py-5 sm:px-7">
            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">06 · Accounts</p>
            <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">Largest installed accounts</h2>
            <p class="mt-1 text-xs text-base-content/50">Top customers by installed products · {{ $selectedRegion }}</p>
        </div>
        <div class="admin-scrollbar flex-1 overflow-x-auto">
            <table class="data-table w-full text-left">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Region</th>
                        <th class="text-right">Installed</th>
                        <th class="text-right">Active</th>
                        <th class="text-right">Warranty</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topAccounts as $row)
                        <tr class="transition-colors hover:bg-base-200/40">
                            <td class="font-semibold text-base-content">
                                <a href="{{ $row['href'] }}" wire:navigate class="group inline-flex items-center gap-1 underline-offset-2 transition hover:text-primary hover:underline" title="View the {{ number_format($row['products']) }} installed products at {{ $row['customer'] }}">{{ $row['customer'] }}<x-mary-icon name="o-arrow-right" class="h-3 w-3 opacity-0 transition-opacity duration-200 group-hover:opacity-100" /></a>
                            </td>
                            <td class="text-base-content/55">{{ $row['region'] ?? '—' }}</td>
                            <td class="tabular-nums text-right font-semibold text-base-content">{{ number_format($row['products']) }}</td>
                            <td class="tabular-nums text-right text-base-content/70">{{ number_format($row['active']) }}</td>
                            <td class="tabular-nums text-right text-base-content/70">{{ number_format($row['warranty']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center">No Product Database data in scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section aria-label="Attention needed" class="admin-surface p-6 sm:p-7">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">07 · Attention</p>
                <h2 class="mt-2 font-display text-xl font-semibold tracking-tight">What needs action</h2>
                <p class="mt-1 text-xs text-base-content/50">Warranty and data-quality signals on the installed base</p>
            </div>
            <a href="{{ route('installed-products') }}" wire:navigate class="no-print inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">Open Product Database <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5" /></a>
        </div>
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach ($attentionSignals as $item)
                @php $toneClass = $signalTones[$item['tone']] ?? 'bg-base-200 text-base-content/60'; @endphp
                <div class="flex gap-3 rounded-xl border border-base-300 bg-base-100 p-4 transition duration-200 hover:-translate-y-0.5 {{ $signalBorders[$item['tone']] ?? '' }}">
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
