<div class="space-y-5">
    <x-admin.page-header eyebrow="Table" title="{{ $title }}" description="{{ $description }}">
        <x-slot name="actions">
            <x-admin.badge tone="primary">{{ $editable ? 'Superadmin editing enabled' : 'Read only' }}</x-admin.badge>
            <button type="button" wire:click="exportExcel" class="admin-secondary-button">
                <x-mary-icon name="o-arrow-down-tray" class="h-4 w-4" />
                Export
            </button>
            <button type="button" x-on:click="$dispatch('open-modal', { name: 'manage-columns' })" class="admin-secondary-button">
                <x-mary-icon name="o-view-columns" class="h-4 w-4" />
                Columns
            </button>
            @if ($editable)
                <button type="button" x-on:click="$dispatch('open-modal', { name: 'import-table' })" class="admin-primary-button">
                    <x-mary-icon name="o-arrow-up-tray" class="h-4 w-4" />
                    Import table
                </button>
            @endif
        </x-slot>
    </x-admin.page-header>

    <x-admin.filter-bar>
        <div class="relative min-w-[240px] flex-1 sm:flex-none">
            <x-mary-icon name="o-magnifying-glass" class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-base-content/40" />
            <input wire:model.live.debounce.300ms="search" type="search" class="admin-control w-full pl-9 placeholder:text-base-content/35" placeholder="Search records..." aria-label="Search" x-on:input="setSearchTerm($el.value)">
        </div>
        @if (count($statuses) > 0)
            <select wire:model.live="statusFilter" class="admin-control">
                <option>All statuses</option>
                @foreach ($statuses as $status)
                    <option>{{ $status }}</option>
                @endforeach
            </select>
        @endif
        @if (count($columnFilters) > 0)
            <button type="button" wire:click="clearColumnFilters" class="admin-secondary-button">
                <x-mary-icon name="o-x-mark" class="h-4 w-4" />
                Clear column filters ({{ count($columnFilters) }})
            </button>
        @endif
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
        <button type="button" wire:click="resetColumnLayout" class="admin-secondary-button ml-auto">
            <x-mary-icon name="o-arrow-path" class="h-4 w-4" />
            Reset layout
        </button>
    </x-admin.filter-bar>

    @if (count($this->drillDownFilters()) > 0)
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

    <section
        class="spreadsheet-shell admin-surface overflow-hidden"
        x-data="managedTableGrid({
            tableKey: @js($tableKey),
            editable: @js($editable),
        })"
        x-init="init($wire)"
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

    {{-- Import wizard, step 1: upload + preview. Nothing is imported until
         the user confirms a manual column mapping in the second popup.
         Import is a Superadmin action, so the whole wizard (including the
         field catalog below) only renders for editors. --}}
    @if ($editable)
    @php
        $importStep = $importResult ? 3 : ($importPreview ? 2 : 1);
    @endphp

    <x-admin.modal name="import-table" title="Import table" description="Upload the Excel/CSV source, preview it, then map its columns to this table before the official import runs. Existing records are updated by stable identifiers." size="lg">
        <div class="space-y-5">
            <div class="flex items-center gap-2">
                @foreach ([1 => 'Source file', 2 => 'Map columns', 3 => 'Import'] as $importStepIndex => $importStepLabel)
                    <span class="flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full border text-[11px] font-bold tabular-nums {{ $importStepIndex < $importStep ? 'border-primary bg-primary text-primary-content' : ($importStepIndex === $importStep ? 'border-primary text-primary' : 'border-base-300 text-base-content/40') }}">
                            @if ($importStepIndex < $importStep)
                                <x-mary-icon name="o-check" class="h-3.5 w-3.5" />
                            @else
                                {{ $importStepIndex }}
                            @endif
                        </span>
                        <span class="text-[11px] font-bold uppercase tracking-[0.1em] {{ $importStepIndex === $importStep ? 'text-base-content' : 'text-base-content/45' }}">{{ $importStepLabel }}</span>
                    </span>
                    @if ($importStepIndex < 3)
                        <span class="h-px w-5 bg-base-300" aria-hidden="true"></span>
                    @endif
                @endforeach
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Excel / CSV file</label>
                <input type="file" wire:model="importFile" class="admin-control w-full">
                <x-input-error :messages="$errors->get('importFile')" class="mt-1.5" />
                <span class="mt-1 block text-xs text-base-content/50" wire:loading wire:target="importFile">Analyzing workbook...</span>
            </div>

            @if ($importPreview)
                @if (count($importAnalysis['sheets'] ?? []) > 1)
                    <div class="max-w-xs">
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Sheet</label>
                        <select wire:model.live="importSheet" class="admin-control w-full">
                            @foreach ($importAnalysis['sheets'] as $importSheetOption)
                                <option value="{{ $importSheetOption['name'] }}">{{ $importSheetOption['name'] }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('importSheet')" class="mt-1.5" />
                    </div>
                @endif

                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Header row</label>
                        <input type="number" min="1" wire:model.live.debounce.400ms="importHeaderRow" class="admin-control w-24 tabular-nums">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">First data row</label>
                        <input type="number" min="{{ $importHeaderRow + 1 }}" wire:model.live.debounce.400ms="importDataStart" class="admin-control w-24 tabular-nums">
                    </div>
                    <div class="ml-auto flex flex-wrap gap-2 text-[11px] font-bold uppercase tracking-[0.08em]">
                        <span class="rounded-full bg-primary/10 px-3 py-1 text-primary tabular-nums">{{ number_format($importPreview['totalRows']) }} data rows</span>
                        <span class="rounded-full bg-base-200 px-3 py-1 text-base-content/60 tabular-nums">{{ $importPreview['totalColumns'] }} columns</span>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-md border border-base-300">
                    <table class="min-w-full border-collapse text-left text-xs">
                        <thead>
                            <tr class="border-b border-base-300 bg-base-200/60">
                                <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-[0.08em] text-base-content/45">Row</th>
                                @foreach (array_slice($importPreview['columns'], 0, 12) as $importPreviewColumn)
                                    <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-[0.08em] text-base-content/45">{{ $importPreviewColumn['letter'] }}</th>
                                @endforeach
                            </tr>
                            <tr class="border-b border-base-300">
                                <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-[0.08em] text-primary">Header</th>
                                @foreach (array_slice($importPreview['columns'], 0, 12) as $importPreviewColumn)
                                    <th class="max-w-[150px] truncate px-3 py-2 font-semibold text-base-content" title="{{ $importPreviewColumn['label'] ?? '(untitled)' }}">{{ $importPreviewColumn['label'] ?? '(untitled)' }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-base-300">
                            @forelse ($importPreview['sampleRows'] as $importSampleRow)
                                <tr class="hover:bg-base-200/50">
                                    <td class="px-3 py-1.5 tabular-nums text-base-content/40">{{ $importSampleRow['rowNumber'] }}</td>
                                    @foreach (array_slice($importPreview['columns'], 0, 12) as $importPreviewColumn)
                                        <td class="max-w-[150px] truncate px-3 py-1.5 text-base-content/80" title="{{ $importSampleRow['cells'][$importPreviewColumn['letter']] ?? '' }}">{{ $importSampleRow['cells'][$importPreviewColumn['letter']] ?? '' }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="13" class="px-3 py-4 text-center text-base-content/50">No data rows below the header - adjust the row numbers above.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-base-content/50">Showing the first {{ min(12, $importPreview['totalColumns']) }} of {{ $importPreview['totalColumns'] }} columns and up to 4 sample rows.</p>
            @endif

            <div class="flex items-center justify-end gap-2 border-t border-base-300 pt-4">
                <button type="button" x-on:click="$dispatch('close-modal', { name: 'import-table' })" class="admin-secondary-button">Close</button>
                <button type="button" wire:click="openImportMapping" x-on:click="$dispatch('close-modal', { name: 'import-table' })" @disabled(! $importPreview) class="admin-primary-button" wire:loading.attr="disabled" wire:target="importFile">
                    <x-mary-icon name="o-table-cells" class="h-4 w-4" />
                    Map columns
                    <x-mary-icon name="o-arrow-right" class="h-4 w-4" />
                </button>
            </div>
        </div>
    </x-admin.modal>

    {{-- Import wizard, step 2: the manual mapping popup. The user binds each
         app field to a source column by hand; the official import only runs
         from here. --}}
    <x-admin.modal name="import-mapping" title="Map columns{{ isset($importTargets['label']) ? ' - '.$importTargets['label'] : '' }}" description="Bind each app field to a source column from {{ $importSheet ?: 'the file' }}. Only mapped columns are imported; existing records are updated by stable identifiers." size="xl">
        <div class="space-y-4">
            @if ($importResult)
                <div class="rounded-md border p-3 text-sm {{ ($importResult['failed'] ?? 0) > 0 || $importResult['status'] === 'failed' ? 'border-error/30 bg-error/10 text-error' : 'border-success/30 bg-success/10 text-success' }}">
                    <p class="font-semibold">Import {{ str_replace('_', ' ', $importResult['status']) }}.</p>
                    <p class="mt-0.5 text-xs opacity-80">{{ number_format($importResult['processed']) }} processed, {{ number_format($importResult['failed']) }} failed &middot; batch #{{ $lastImportId }}</p>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.08em] text-primary tabular-nums">{{ $importMappedCount }} / {{ count($importTargets['fields'] ?? []) }} fields mapped</span>
                    @foreach ($importMissingRequired as $importMissingLabel)
                        <span class="rounded-full bg-error/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.08em] text-error">Required: {{ $importMissingLabel }}</span>
                    @endforeach
                </div>
            @endif

            <div class="max-h-[46vh] divide-y divide-base-300 overflow-y-auto rounded-md border border-base-300">
                @foreach ($importTargets['fields'] ?? [] as $importField)
                    @php
                        $importMappedColumn = collect($importPreview['columns'] ?? [])->firstWhere('letter', $importMapping[$importField['key']] ?? '');
                        $importMappedSamples = collect($importMappedColumn['samples'] ?? [])->filter()->take(2)->implode(' | ');
                    @endphp
                    <div class="grid grid-cols-1 items-center gap-2 px-4 py-2.5 sm:grid-cols-[minmax(0,200px)_minmax(0,1fr)_minmax(0,200px)] sm:gap-3 {{ $importField['required'] && ($importMapping[$importField['key']] ?? '') === '' ? 'bg-error/5' : '' }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-base-content">
                                {{ $importField['label'] }}
                                @if ($importField['required'])
                                    <span class="ml-0.5 text-error" title="Required field">*</span>
                                @endif
                            </p>
                            <p class="text-[11px] uppercase tracking-[0.06em] text-base-content/45">{{ $importField['kind'] }}</p>
                        </div>
                        <div class="min-w-0">
                            <select wire:model.live="importMapping.{{ $importField['key'] }}" class="admin-control w-full font-mono text-xs" @disabled((bool) $importResult)>
                                <option value="">-- not mapped --</option>
                                @foreach ($importPreview['columns'] ?? [] as $importPreviewColumn)
                                    <option value="{{ $importPreviewColumn['letter'] }}">{{ $importPreviewColumn['letter'] }} &middot; {{ $importPreviewColumn['label'] ?? '(untitled)' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <p class="hidden truncate text-xs text-base-content/50 sm:block" title="{{ $importMappedSamples }}">
                            @if ($importMappedSamples !== '')
                                e.g. {{ $importMappedSamples }}
                            @else
                                --
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>

            <x-input-error :messages="$errors->get('importMapping')" class="" />

            <div class="flex items-center justify-between gap-2 border-t border-base-300 pt-4">
                <div>
                    @if ($importResult)
                        <button type="button" wire:click="resetImportWizard" x-on:click="$dispatch('close-modal', { name: 'import-mapping' }); $dispatch('open-modal', { name: 'import-table' })" class="admin-secondary-button">
                            <x-mary-icon name="o-arrow-path" class="h-4 w-4" />
                            New import
                        </button>
                    @else
                        <button type="button" x-on:click="$dispatch('close-modal', { name: 'import-mapping' }); $dispatch('open-modal', { name: 'import-table' })" class="admin-secondary-button">Back</button>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="$dispatch('close-modal', { name: 'import-mapping' }); $dispatch('close-modal', { name: 'import-table' })" class="admin-secondary-button">
                        {{ $importResult ? 'Done' : 'Cancel' }}
                    </button>
                    @if (! $importResult)
                        <button type="button" wire:click="executeMappedImport" class="admin-primary-button" wire:loading.attr="disabled" wire:target="executeMappedImport">
                            <span wire:loading.remove wire:target="executeMappedImport">Start import ({{ number_format($importPreview['totalRows'] ?? 0) }} rows)</span>
                            <span wire:loading wire:target="executeMappedImport">Importing...</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </x-admin.modal>
    @endif
</div>
