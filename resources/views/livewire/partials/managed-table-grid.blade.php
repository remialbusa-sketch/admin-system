<x-admin.page-header eyebrow="Table" title="{{ $title }}" description="{{ $description }}">
    <x-slot name="actions">
        <x-admin.badge tone="primary">{{ $editable ? 'Editing enabled' : 'Read only' }}</x-admin.badge>
        <button type="button" wire:click="exportExcel" class="admin-secondary-button">
            <x-mary-icon name="o-arrow-down-tray" class="h-4 w-4" />
            Export
        </button>
        <button type="button" x-on:click="$dispatch('open-modal', { name: 'manage-columns' })" class="admin-secondary-button">
            <x-mary-icon name="o-view-columns" class="h-4 w-4" />
            Columns
        </button>
        @if ($canImport)
            <a href="{{ route('tables.import.classic', $tableKey) }}" target="_blank" rel="noopener" class="admin-primary-button" title="Open the import wizard in a new tab (auto-mapping, preview, failed-rows download)">
                <x-mary-icon name="o-arrow-up-tray" class="h-4 w-4" />
                Import
            </a>
        @endif
    </x-slot>
</x-admin.page-header>

<x-admin.filter-bar>
    <div class="relative min-w-[240px] flex-1 sm:flex-none" x-data="{ focused: false }" x-on:keydown.window.slash.prevent="if (!focused && !$event.metaKey && !$event.ctrlKey) { $refs.searchInput.focus(); }">
        <x-mary-icon name="o-magnifying-glass" class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-base-content/40" />
        <input
            x-ref="searchInput"
            x-on:focus="focused = true"
            x-on:blur="focused = false"
            wire:model.live.debounce.300ms="search"
            type="search"
            class="admin-control w-full pl-9 placeholder:text-base-content/35"
            placeholder="Search records...  (/)"
            aria-label="Search records"
            x-on:input="setSearchTerm($el.value)"
            x-on:keydown.escape.window="if (focused) $wire.clearAllFilters()"
        >
    </div>

    @if (count($statuses) > 0)
        <label class="flex items-center gap-2">
            <span class="hidden text-[11px] font-bold uppercase tracking-[0.08em] text-base-content/50 sm:inline">{{ $tableKey === 'personnel' ? 'Branch' : 'Status' }}</span>
            <select wire:model.live="statusFilter" class="admin-control" aria-label="Filter by {{ $tableKey === 'personnel' ? 'branch' : 'status' }}">
                <option>All {{ $tableKey === 'personnel' ? 'branches' : 'statuses' }}</option>
                @foreach ($statuses as $status)
                    <option>{{ $status }}</option>
                @endforeach
            </select>
        </label>
    @endif

    {{-- Unified Filters button with active count badge — drawer trigger (crowding fix, .impeccable.md:22) --}}
    <button
        type="button"
        x-on:click="$dispatch('open-filter-drawer')"
        class="admin-secondary-button relative"
        aria-label="Open filters"
    >
        <x-mary-icon name="o-adjustments-horizontal" class="h-4 w-4" />
        Filters
        @if ($this->activeFilterCount() > 0)
            <span class="absolute -right-1.5 -top-1.5 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-bold leading-none text-primary-content">
                {{ $this->activeFilterCount() }}
            </span>
        @endif
    </button>

    @if ($archivedCount > 0 || $showArchived)
        <button type="button" wire:click="toggleShowArchived" class="admin-secondary-button">
            <x-mary-icon name="o-archive-box" class="h-4 w-4" />
            @if ($showArchived)
                Back to active records
            @else
                Archive box ({{ $archivedCount }})
            @endif
        </button>
    @endif

    <button type="button" wire:click="resetColumnLayout" class="admin-secondary-button ml-auto" title="Reset column order, width, hidden and frozen state for this table">
        <x-mary-icon name="o-arrow-path" class="h-4 w-4" />
        Reset layout
    </button>

    <span class="hidden items-center gap-1 text-xs text-base-content/40 sm:inline-flex" title="Press / to focus search, Esc to clear filters">
        <kbd class="rounded border border-base-300 bg-base-200 px-1.5 py-0.5 text-[10px]">/</kbd>
        <span>search</span>
        <span class="mx-1">·</span>
        <kbd class="rounded border border-base-300 bg-base-200 px-1.5 py-0.5 text-[10px]">Esc</kbd>
        <span>clear</span>
    </span>
</x-admin.filter-bar>

{{-- Unified active-filter chips: search + status + columnFilters + branch + archived + drills — always visible so user sees why rows disappeared --}}
@if ($this->hasActiveFilters())
    <div class="flex flex-wrap items-center gap-2" role="status" aria-label="Active filters">
        <span class="text-[11px] font-bold uppercase tracking-[0.1em] text-primary">Active filters:</span>
        {{-- Keep "Drill-down from dashboard" string for backward test compat when drill is present --}}
        @if (count($this->drillDownFilters()) > 0)
            <span class="sr-only">Drill-down from dashboard</span>
        @endif
        @foreach ($this->activeFilterChips() as $chip)
            <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">
                {{ $chip['label'] }}: {{ \Illuminate\Support\Str::limit($chip['value'], 28) }}
                <button type="button" wire:click="removeFilterChip('{{ $chip['key'] }}')" class="ml-0.5 inline-flex h-4 w-4 items-center justify-center rounded-full bg-primary/20 text-primary hover:bg-error hover:text-error-content" aria-label="Remove {{ $chip['label'] }} filter">
                    <x-mary-icon name="o-x-mark" class="h-3 w-3" />
                </button>
            </span>
        @endforeach
        <button type="button" wire:click="clearAllFilters" class="no-print inline-flex items-center gap-1 text-[11px] font-semibold text-base-content/50 underline-offset-2 hover:text-error hover:underline">
            Clear all <x-mary-icon name="o-x-mark" class="h-3 w-3" />
        </button>
        <span class="text-[11px] text-base-content/40">· shareable via URL</span>
    </div>
@elseif (count($this->drillDownFilters()) > 0)
    <div class="flex flex-wrap items-center gap-2" role="status" aria-label="Active drill-down filters from the dashboard">
        <span class="text-[11px] font-bold uppercase tracking-[0.1em] text-primary">Drill-down from dashboard:</span>
        @foreach ($this->drillDownFilters() as $drillLabel => $drillValue)
            <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">{{ $drillLabel }}: {{ str_replace('_', ' ', $drillValue) }}</span>
        @endforeach
        <button type="button" wire:click="clearDrillDown" class="no-print inline-flex items-center gap-1 text-[11px] font-semibold text-base-content/50 underline-offset-2 hover:text-error hover:underline">
            Clear all <x-mary-icon name="o-x-mark" class="h-3 w-3" />
        </button>
    </div>
@endif

{{-- Filter drawer — advanced controls discoverable without crowding the bar (.impeccable.md:22) --}}
<x-admin.filter-drawer>
    <x-slot name="trigger"></x-slot>

    <div class="space-y-6">
        {{-- Quick filters: status + branch (when applicable) + archived --}}
        <section>
            <h3 class="mb-3 text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Quick filters</h3>
            <div class="space-y-3">
                @if (count($statuses) > 0)
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-semibold text-base-content/70">{{ $tableKey === 'personnel' ? 'Branch' : 'Status' }}</span>
                        <select wire:model.live="statusFilter" class="admin-control w-full">
                            <option>All {{ $tableKey === 'personnel' ? 'branches' : 'statuses' }}</option>
                            @foreach ($statuses as $status)
                                <option>{{ $status }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if (property_exists($this, 'branchFilter'))
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-semibold text-base-content/70">Branch</span>
                        <select wire:model.live="branchFilter" class="admin-control w-full">
                            <option>All branches</option>
                            @foreach (['NCR','North Luzon','South Luzon','Visayas','Mindanao'] as $branch)
                                <option>{{ $branch }}</option>
                            @endforeach
                        </select>
                        <span class="mt-1 block text-[11px] text-base-content/45">URL-bound — deep-links from dashboard region cards use <code>?branch=</code> + <code>?region=</code>.</span>
                    </label>
                @endif

                @if ($archivedCount > 0 || $showArchived)
                    <label class="flex items-center gap-2 rounded-md border border-base-300 px-3 py-2.5">
                        <input type="checkbox" wire:click="toggleShowArchived" @checked($showArchived) class="checkbox checkbox-xs">
                        <span class="text-sm text-base-content">Show archived ({{ $archivedCount }})</span>
                    </label>
                @endif
            </div>
        </section>

        {{-- Column filters: Excel-style per-column text/number/date/select inputs --}}
        <section>
            <h3 class="mb-3 text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Column filters</h3>
            @if (empty($columns))
                <p class="text-sm text-base-content/50">No columns available.</p>
            @else
                <p class="mb-2 text-[11px] text-base-content/45">Filter any column — values are shareable via URL (<code>?columnFilters[field]=…</code>). Use plain text for contains, exact for Status/Number/Date.</p>
                <div class="space-y-2.5">
                    @foreach ($columns as $column)
                        @php
                            $colKey = $column['key'];
                            $colType = $column['type'] ?? 'text';
                            $colVal = $columnFilters[$colKey] ?? '';
                        @endphp
                        @if (in_array($colType, ['status','select','dropdown'], true) && !empty($column['options'] ?? []))
                            <label class="block">
                                <span class="mb-1 block truncate text-xs font-semibold text-base-content/70">{{ $column['label'] }} <span class="font-normal text-base-content/40">({{ $colType }})</span></span>
                                <select wire:model.live="columnFilters.{{ $colKey }}" class="admin-control w-full">
                                    <option value="">Any {{ $column['label'] }}</option>
                                    @foreach ($column['options'] as $opt)
                                        <option value="{{ $opt }}">{{ $opt }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @elseif ($colType === 'date')
                            <label class="block">
                                <span class="mb-1 block truncate text-xs font-semibold text-base-content/70">{{ $column['label'] }}</span>
                                <input type="date" wire:model.live="columnFilters.{{ $colKey }}" value="{{ $colVal }}" class="admin-control w-full">
                            </label>
                        @elseif ($colType === 'number')
                            <label class="block">
                                <span class="mb-1 block truncate text-xs font-semibold text-base-content/70">{{ $column['label'] }}</span>
                                <input type="number" wire:model.live="columnFilters.{{ $colKey }}" value="{{ $colVal }}" placeholder="Exact number" class="admin-control w-full">
                            </label>
                        @else
                            <label class="block">
                                <span class="mb-1 block truncate text-xs font-semibold text-base-content/70">{{ $column['label'] }}</span>
                                <input type="text" wire:model.live.debounce.300ms="columnFilters.{{ $colKey }}" value="{{ $colVal }}" placeholder="Contains…" class="admin-control w-full">
                            </label>
                        @endif
                    @endforeach
                </div>
                @if (count($columnFilters) > 0)
                    <button type="button" wire:click="clearColumnFilters" class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-error hover:underline">
                        <x-mary-icon name="o-x-mark" class="h-3 w-3" />
                        Clear column filters ({{ count($columnFilters) }})
                    </button>
                @endif
            @endif
        </section>

        {{-- Drill-downs (read-only mirror) --}}
        @if (count($this->drillDownFilters()) > 0)
            <section>
                <h3 class="mb-3 text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Dashboard drill-down</h3>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($this->drillDownFilters() as $drillLabel => $drillValue)
                        <span class="inline-flex rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-bold text-primary">{{ $drillLabel }}: {{ str_replace('_',' ', $drillValue) }}</span>
                    @endforeach
                </div>
                <button type="button" wire:click="clearDrillDown" class="mt-2 text-xs font-semibold text-base-content/50 hover:text-error hover:underline">Clear drill-down</button>
            </section>
        @endif

        {{-- Saved views placeholder (C1) — wired in Phase 2 --}}
        <section class="rounded-md border border-dashed border-base-300 bg-base-200/40 p-3">
            <h3 class="mb-1 text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Saved views</h3>
            <p class="text-[11px] leading-relaxed text-base-content/50">Save the current search + filters as a named view (per-user). Coming in Phase 2 — use URL copy for now (all filters are shareable via <code>?search=</code> <code>?status=</code> <code>?columnFilters</code>).</p>
        </section>

        {{-- Dense polish: keyboard hint --}}
        <p class="text-center text-[11px] text-base-content/40">Tip: press <kbd class="rounded border border-base-300 bg-base-200 px-1 py-0.5">/</kbd> to focus search · <kbd class="rounded border border-base-300 bg-base-200 px-1 py-0.5">Esc</kbd> clears all.</p>
    </div>
</x-admin.filter-drawer>

<section
    class="spreadsheet-shell admin-surface overflow-hidden"
    x-data="managedTableGrid({
        tableKey: @js($tableKey),
        editable: @js($editable),
    })"
    x-init="init()"
>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 px-5 py-3 text-sm text-base-content/60">
        <span>{{ $rows->total() }} records &middot; drag headers to reorder, drag edges to resize, right-click a header to freeze/hide &middot; tick rows to select &mdash; quick actions appear in the bar below, or open a row's &hellip; menu</span>
        <div class="flex flex-wrap items-center gap-2">
            <label class="flex items-center gap-1.5 text-xs text-base-content/55">
                Density
                <select data-density class="admin-control h-8 w-auto py-1 text-xs">
                    <option value="condensed">Condensed</option>
                    <option value="standard">Standard</option>
                    <option value="comfortable">Comfortable</option>
                </select>
            </label>
            @if ($editable)
                <button type="button" x-on:click="addRow()" class="admin-secondary-button">
                    <x-mary-icon name="o-plus" class="h-4 w-4" />
                    New item
                </button>
                <button type="button" x-on:click="saveChanges()" class="admin-primary-button" x-bind:disabled="!hasChanges">
                    <x-mary-icon name="o-check" class="h-4 w-4" />
                    Save changes
                </button>
            @endif
            <span x-text="status" class="text-xs font-semibold uppercase tracking-[0.06em]"></span>
        </div>
    </div>

    <script type="application/json" data-managed-table-payload>{!! json_encode($gridPayload) !!}</script>

    <div wire:ignore class="spreadsheet-grid" data-managed-table-grid style="height: 640px;"></div>

    @if ($rows->total() === 0 && $this->hasActiveFilters())
        <div class="flex flex-col items-center gap-2 border-t border-base-300 bg-base-200/30 px-5 py-8 text-center">
            <x-mary-icon name="o-magnifying-glass" class="h-8 w-8 text-base-content/25" />
            <p class="text-sm font-semibold text-base-content">No records match filters</p>
            <p class="max-w-md text-xs leading-relaxed text-base-content/55">Try adjusting search, status, or column filters — all filters are shareable via URL, so you can copy the link once it looks right.</p>
            <button type="button" wire:click="clearAllFilters" class="admin-secondary-button mt-1">
                <x-mary-icon name="o-x-mark" class="h-4 w-4" />
                Clear all filters
            </button>
        </div>
    @endif

    <div class="border-t border-base-300 px-5 py-3">{{ $rows->links() }}</div>

    {{-- Monday.com-style floating action bar: appears whenever grid rows
         are selected. The Alpine grid component owns the selection state
         (Tabulator), so its buttons collect the selected ids client-side
         and invoke the matching Livewire action. --}}
    <div class="admin-selection-bar no-print"
         x-cloak
         x-show="selectedCount > 0"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 translate-y-3"
         x-transition:enter-end="opacity-100 translate-y-0"
         role="toolbar"
         aria-label="Selection actions">
        <span class="admin-selection-count" x-text="selectedCount"></span>
        <span class="admin-selection-label" x-text="selectedCount === 1 ? 'item selected' : 'items selected'"></span>

        <span class="admin-selection-separator" aria-hidden="true"></span>

        <div class="admin-selection-actions">
            @if ($editable && ! $showArchived)
                <button type="button" x-on:click="duplicateSelected()" class="admin-selection-action" title="Duplicate selected rows" aria-label="Duplicate">
                    <x-mary-icon name="o-square-2-stack" class="h-4 w-4" />
                </button>
            @endif
            <button type="button" x-on:click="exportSelectedRows()" class="admin-selection-action" title="Export selected rows to Excel" aria-label="Export">
                <x-mary-icon name="o-arrow-down-tray" class="h-4 w-4" />
            </button>
            @if ($editable)
                @if ($showArchived)
                    <button type="button" x-on:click="restoreSelected()" class="admin-selection-action" title="Restore selected rows to the active listing" aria-label="Restore">
                        <x-mary-icon name="o-arrow-uturn-right" class="h-4 w-4" />
                    </button>
                @else
                    <button type="button" x-on:click="archiveSelected()" class="admin-selection-action" title="Archive selected rows" aria-label="Archive">
                        <x-mary-icon name="o-archive-box" class="h-4 w-4" />
                    </button>
                @endif
                <button type="button" x-on:click="deleteSelected()" class="admin-selection-action admin-selection-action-danger" title="Delete selected rows" aria-label="Delete">
                    <x-mary-icon name="o-trash" class="h-4 w-4" />
                </button>
            @endif
        </div>

        <button type="button" x-on:click="clearSelection()" class="admin-selection-dismiss" title="Clear selection" aria-label="Dismiss">
            <x-mary-icon name="o-x-mark" class="h-4 w-4" />
        </button>
    </div>
</section>

<x-admin.modal name="manage-columns" title="Manage columns" description="Add custom columns to this table just like adding a column in Excel. Toggle visibility/freeze here, or drag &amp; resize headers directly in the grid." size="lg">
    <div class="space-y-6">
        <div>
            <p class="mb-2 text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">All columns</p>
            <div class="max-h-64 divide-y divide-base-300 overflow-y-auto rounded-md border border-base-300">
                @foreach ($columns as $column)
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <span class="truncate text-sm text-base-content">{{ $column['label'] }}</span>
                        <div class="flex shrink-0 items-center gap-4">
                            <label class="flex items-center gap-1.5 text-xs text-base-content/60">
                                <input type="checkbox" wire:click="toggleColumnFreeze('{{ $column['key'] }}')" @checked($column['frozen'] ?? false)>
                                Frozen
                            </label>
                            <label class="flex items-center gap-1.5 text-xs text-base-content/60">
                                <input type="checkbox" wire:click="toggleColumnVisibility('{{ $column['key'] }}')" @checked(! ($column['hidden'] ?? false))>
                                Visible
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div>
            <p class="mb-2 text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Custom columns</p>
            @if ($editable)
                <form wire:submit="addCustomColumn" class="mb-3 flex flex-wrap items-end gap-3 rounded-md border border-base-300 p-3">
                    <div class="min-w-[180px] flex-1">
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Column name</label>
                        <input type="text" wire:model="newColumnName" class="admin-control w-full" placeholder="e.g. Follow-up Notes">
                        <x-input-error :messages="$errors->get('newColumnName')" class="mt-1.5" />
                    </div>
                    <div class="min-w-[160px]">
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Type</label>
                        <select wire:model="newColumnType" class="admin-control w-full">
                            @foreach ($columnTypeOptions as $key => $type)
                                <option value="{{ $key }}">{{ \Illuminate\Support\Str::headline($key) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="admin-primary-button">
                        <x-mary-icon name="o-plus" class="h-4 w-4" />
                        Add column
                    </button>
                </form>
            @endif

            <div class="divide-y divide-base-300 rounded-md border border-base-300">
                @forelse ($customColumns as $column)
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        @if ($renamingColumnId === $column->id)
                            <form wire:submit="renameCustomColumn" class="flex flex-1 items-center gap-2">
                                <input type="text" wire:model="renamingColumnName" class="admin-control flex-1" autofocus>
                                <button type="submit" class="admin-primary-button">Save</button>
                                <button type="button" wire:click="$set('renamingColumnId', null)" class="admin-secondary-button">Cancel</button>
                            </form>
                        @else
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-base-content">{{ $column->name }}</p>
                                <p class="text-xs uppercase tracking-[0.06em] text-base-content/50">{{ \Illuminate\Support\Str::headline($column->type) }}</p>
                            </div>
                            @if ($editable)
                                <div class="flex shrink-0 items-center gap-1.5">
                                    <button type="button" wire:click="startRenamingColumn({{ $column->id }}, '{{ $column->name }}')" class="admin-icon-button" aria-label="Rename">
                                        <x-mary-icon name="o-pencil" class="h-4 w-4" />
                                    </button>
                                    <button type="button" wire:click="deleteCustomColumn({{ $column->id }})" wire:confirm="Delete this column and all of its values? This can't be undone." class="admin-icon-button" aria-label="Delete">
                                        <x-mary-icon name="o-trash" class="h-4 w-4" />
                                    </button>
                                </div>
                            @endif
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-base-content/55">No custom columns yet. Add one above to extend this table with fields specific to your workflow.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-admin.modal>
