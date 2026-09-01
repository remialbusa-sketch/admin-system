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
            <span>{{ $rows->total() }} records &middot; drag headers to reorder, drag edges to resize, right-click a header to freeze/hide &middot; tick rows to select for bulk actions</span>
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
                <button type="button" data-bulk-delete class="admin-secondary-button admin-bulk hidden" x-on:click="deleteSelected()" x-show="selectedCount() > 0">
                    <x-mary-icon name="o-trash" class="h-4 w-4" />
                    Delete <span data-bulk-delete-count>0</span> selected
                </button>
                <span x-text="status" class="text-xs font-semibold uppercase tracking-[0.06em]"></span>
            </div>
        </div>

        <script type="application/json" data-managed-table-payload>{!! json_encode($gridPayload) !!}</script>

        <div wire:ignore class="spreadsheet-grid" data-managed-table-grid style="height: 640px;"></div>

        <div class="border-t border-base-300 px-5 py-3">{{ $rows->links() }}</div>
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

    <x-admin.modal name="import-table" title="Import table" description="Re-import the matching Excel/CSV into this managed table. Existing records are updated by stable identifiers.">
        <form wire:submit="startImport" class="space-y-5">
            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Source table</label>
                <select wire:model="importTable" class="admin-control w-full">
                    <option value="">Select the table you are importing into...</option>
                    @foreach ($tableOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('importTable')" class="mt-1.5" />
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Excel / CSV file</label>
                <input type="file" wire:model="importFile" class="admin-control w-full">
                <x-input-error :messages="$errors->get('importFile')" class="mt-1.5" />
            </div>
            @if ($importResult)
                <div class="rounded-md border border-success/30 bg-success/10 p-3 text-sm text-success">
                    Import {{ $importResult['status'] }}: {{ $importResult['processed'] }} processed, {{ $importResult['failed'] }} failed.
                </div>
            @endif
            <div class="flex items-center justify-end gap-2 border-t border-base-300 pt-4">
                <button type="button" x-on:click="$dispatch('close-modal', { name: 'import-table' })" class="admin-secondary-button">Close</button>
                <button type="submit" class="admin-primary-button" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="startImport">Start import</span>
                    <span wire:loading wire:target="startImport">Importing...</span>
                </button>
            </div>
        </form>
    </x-admin.modal>
</div>
