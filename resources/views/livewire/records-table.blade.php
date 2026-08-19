@php
    $statusTone = [
        'New' => 'info',
        'In progress' => 'warning',
        'Blocked' => 'danger',
        'Resolved' => 'success',
    ];

    $priorityTone = [
        'High' => 'danger',
        'Medium' => 'warning',
        'Low' => 'neutral',
    ];
@endphp

<div class="space-y-5">
    <x-admin.page-header
        eyebrow="Operations"
        title="Records table"
        description="Review imported service records, then enrich the current record with statuses, labels, and custom columns."
    >
        <x-slot name="actions">
            <button type="button" x-on:click="$dispatch('open-drawer', { name: 'import-records' })" class="admin-secondary-button">
                <x-mary-icon name="o-arrow-up-tray" class="h-4 w-4" />
                Import file
            </button>
            <button type="button" x-on:click="$dispatch('open-modal', { name: 'add-column' })" class="admin-primary-button">
                <x-mary-icon name="o-plus" class="h-4 w-4" />
                Add column
            </button>
        </x-slot>
    </x-admin.page-header>

    <x-admin.filter-bar>
        <div class="relative min-w-[220px] flex-1 sm:flex-none">
            <x-mary-icon name="o-magnifying-glass" class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-base-content/40" />
            <input wire:model.live.debounce.300ms="search" type="search" class="admin-control w-full pl-9 placeholder:text-base-content/35" placeholder="Search records..." aria-label="Search records">
        </div>

        <select wire:model.live="statusFilter" class="admin-control min-w-[150px]" aria-label="Filter by status">
            <option>All statuses</option>
            @foreach ($statuses as $status)
                <option>{{ $status }}</option>
            @endforeach
        </select>

        <select wire:model.live="labelFilter" class="admin-control min-w-[135px]" aria-label="Filter by label">
            <option>All labels</option>
            @foreach ($labels as $label => $tone)
                <option>{{ $label }}</option>
            @endforeach
        </select>

        @if ($search !== '' || $statusFilter !== 'All statuses' || $labelFilter !== 'All labels')
            <button type="button" wire:click="clearFilters" class="ml-auto text-xs font-bold text-primary hover:underline">Clear filters</button>
        @endif
    </x-admin.filter-bar>

    <div class="flex flex-col gap-3 text-xs text-base-content/55 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-success"></span>
            <span>{{ count($filteredRecords) }} of {{ count($records) }} records visible</span>
            <span class="text-base-content/30">-</span>
            <span>Source: {{ $importedFileName }}</span>
        </div>
        <span class="tabular-nums">{{ count($columns) }} columns</span>
    </div>

    <x-admin.table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column['label'] }}</th>
                @endforeach
                <th>Labels</th>
                <th class="w-12"><span class="sr-only">Open record</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filteredRecords as $record)
                @php
                    $firstColumnKey = $columns[0]['key'] ?? null;
                    $recordIdentifier = $record['ticket_id'] ?? ($firstColumnKey ? ($record[$firstColumnKey] ?? 'Record') : 'Record');
                @endphp
                <tr>
                    @foreach ($columns as $column)
                        @php
                            $value = $record[$column['key']] ?? '';
                            $isStatus = $column['key'] === 'status';
                            $isPriority = $column['key'] === 'priority';
                        @endphp
                        <td>
                            @if ($isStatus)
                                <x-admin.badge :tone="$statusTone[$value] ?? 'neutral'">{{ $value ?: 'Unassigned' }}</x-admin.badge>
                            @elseif ($isPriority)
                                <x-admin.badge :tone="$priorityTone[$value] ?? 'neutral'">{{ $value ?: 'Unassigned' }}</x-admin.badge>
                            @elseif ($column['key'] === 'ticket_id')
                                <button type="button" wire:click="openRecord({{ $record['id'] }})" class="font-bold text-primary hover:underline">{{ $value }}</button>
                            @else
                                <span class="{{ $column['key'] === 'technician' ? 'font-semibold text-base-content' : 'text-base-content/70' }}">{{ $value ?: '-' }}</span>
                            @endif
                        </td>
                    @endforeach
                    <td>
                        <div class="flex max-w-[220px] flex-wrap gap-1.5">
                            @forelse ($record['labels'] ?? [] as $label)
                                <x-admin.badge :tone="$labels[$label] ?? 'neutral'">{{ $label }}</x-admin.badge>
                            @empty
                                <span class="text-xs text-base-content/35">No labels</span>
                            @endforelse
                        </div>
                    </td>
                    <td>
                        <button type="button" wire:click="openRecord({{ $record['id'] }})" class="admin-icon-button" title="Open record" aria-label="Open {{ $recordIdentifier }}">
                            <x-mary-icon name="o-chevron-right" class="h-4 w-4" />
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) + 2 }}" class="px-4 py-12 text-center">
                        <x-mary-icon name="o-document-magnifying-glass" class="mx-auto h-8 w-8 text-base-content/30" />
                        <p class="mt-3 text-sm font-bold text-base-content">No records match these filters</p>
                        <p class="mt-1 text-xs text-base-content/55">Clear the filters or import a different file.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-admin.table>

    <x-admin.drawer name="import-records" title="Import records" description="Upload a CSV or Excel file with a header row. Existing preview data will be replaced.">
        <form wire:submit="importRecords" class="space-y-5">
            <div>
                <label for="import-file" class="mb-2 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Source file</label>
                <label for="import-file" class="flex cursor-pointer flex-col items-center justify-center border border-dashed border-base-300 px-5 py-10 text-center transition hover:border-primary hover:bg-primary/5">
                    <x-mary-icon name="o-document-arrow-up" class="h-8 w-8 text-primary" />
                    <span class="mt-3 text-sm font-bold text-base-content">Choose a file</span>
                    <span class="mt-1 text-xs text-base-content/55">CSV, XLS, or XLSX - up to 10 MB</span>
                    <input id="import-file" wire:model="importFile" type="file" accept=".csv,.txt,.xls,.xlsx" class="sr-only">
                </label>
                @if ($importFile)
                    <p class="mt-2 text-xs font-semibold text-success">{{ $importFile->getClientOriginalName() }} selected</p>
                @endif
                <x-input-error :messages="$errors->get('importFile')" class="mt-2" />
            </div>

            <div class="border border-base-300 bg-base-200/40 p-4 text-xs leading-5 text-base-content/65">
                <p class="font-bold text-base-content">Import behavior</p>
                <ul class="mt-2 list-disc space-y-1 pl-4">
                    <li>The first row becomes the table columns.</li>
                    <li>Each row becomes a record that can be enriched in the detail panel.</li>
                    <li>Importing replaces the current preview dataset.</li>
                </ul>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-base-300 pt-4">
                <button type="button" x-on:click="$dispatch('close-drawer', { name: 'import-records' })" class="admin-secondary-button">Cancel</button>
                <button type="submit" class="admin-primary-button" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="importRecords">Import records</span>
                    <span wire:loading wire:target="importRecords">Reading file...</span>
                </button>
            </div>
        </form>
    </x-admin.drawer>

    <x-admin.modal name="add-column" title="Add a column" description="Extend the current table without losing imported records.">
        <form wire:submit="addColumn" class="space-y-4">
            <div>
                <label for="column-name" class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Column name</label>
                <input id="column-name" wire:model="newColumnName" type="text" class="admin-control w-full" placeholder="e.g. Service area" autofocus>
                <x-input-error :messages="$errors->get('newColumnName')" class="mt-1.5" />
            </div>
            <div>
                <label for="column-type" class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Column type</label>
                <select id="column-type" wire:model="newColumnType" class="admin-control w-full">
                    <option value="text">Text</option>
                    <option value="status">Selection</option>
                    <option value="date">Date</option>
                    <option value="number">Number</option>
                </select>
                <p class="mt-1.5 text-xs text-base-content/50">Selection columns use the available status options in the detail panel.</p>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-base-300 pt-4">
                <button type="button" x-on:click="$dispatch('close-modal', { name: 'add-column' })" class="admin-secondary-button">Cancel</button>
                <button type="submit" class="admin-primary-button">Add column</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.drawer name="record-details" title="Record details" description="Customize field values, selections, and labels for the current record.">
        @if ($selectedRecord)
            @php
                $assignedLabels = $selectedRecord['labels'] ?? [];
            @endphp

            <div class="space-y-6">
                <div class="flex items-start justify-between gap-4 border-b border-base-300 pb-5">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.1em] text-primary">{{ $selectedRecord['ticket_id'] ?? 'Imported record' }}</p>
                        <h3 class="mt-1 font-display text-2xl font-semibold text-base-content">{{ $selectedRecord['technician'] ?? 'Unnamed record' }}</h3>
                        <p class="mt-1 text-sm text-base-content/55">{{ $selectedRecord['region'] ?? 'No region assigned' }}</p>
                    </div>
                    <x-admin.badge :tone="$statusTone[$selectedRecord['status'] ?? ''] ?? 'neutral'">{{ $selectedRecord['status'] ?? 'Unassigned' }}</x-admin.badge>
                </div>

                <section>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-bold text-base-content">Field values</h3>
                            <p class="mt-1 text-xs leading-5 text-base-content/55">Edit each field or choose from its configured options.</p>
                        </div>
                        <x-admin.badge tone="primary">{{ count($columns) }} fields</x-admin.badge>
                    </div>

                    <div class="mt-4 space-y-3">
                        @foreach ($columns as $column)
                            @php
                                $fieldValue = $selectedRecord[$column['key']] ?? '';
                                $options = $fieldOptions[$column['key']] ?? [];
                                $fieldType = $options !== [] ? 'selection' : $column['type'];
                                $inputType = $column['type'] === 'number' ? 'number' : 'text';
                            @endphp
                            <div class="border border-base-300 p-3">
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <label for="record-field-{{ $column['key'] }}" class="text-xs font-bold text-base-content">{{ $column['label'] }}</label>
                                    <span class="text-[10px] font-bold uppercase tracking-[0.1em] text-base-content/40">{{ $fieldType }}</span>
                                </div>

                                @if ($options !== [])
                                    <select
                                        id="record-field-{{ $column['key'] }}"
                                        wire:change="handleFieldSelection(@js($column['key']), $event.target.value)"
                                        class="admin-control w-full"
                                    >
                                        <option value="">Unassigned</option>
                                        @foreach ($options as $option)
                                            <option value="{{ $option }}" @selected($fieldValue === $option)>{{ $option }}</option>
                                        @endforeach
                                        <option value="__add_selection__">+ Add selection or label</option>
                                    </select>
                                @else
                                    <input
                                        id="record-field-{{ $column['key'] }}"
                                        wire:change="updateSelectedField(@js($column['key']), $event.target.value)"
                                        type="{{ $inputType }}"
                                        value="{{ $fieldValue }}"
                                        class="admin-control w-full"
                                        placeholder="Enter {{ strtolower($column['label']) }}"
                                    >
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="border-t border-base-300 pt-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-bold text-base-content">Labels</h3>
                            <p class="mt-1 text-xs leading-5 text-base-content/55">Select one or more labels for this record.</p>
                        </div>
                        <span class="text-xs font-semibold text-base-content/45">{{ count($assignedLabels) }} assigned</span>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ($labels as $label => $tone)
                            @php
                                $labelSelected = in_array($label, $assignedLabels, true);
                                $labelDotClasses = [
                                    'danger' => 'bg-error',
                                    'info' => 'bg-info',
                                    'success' => 'bg-success',
                                    'warning' => 'bg-warning',
                                    'neutral' => 'bg-base-content/45',
                                ][$tone] ?? 'bg-base-content/45';
                            @endphp
                            <button
                                type="button"
                                wire:click="toggleLabel(@js($label))"
                                aria-pressed="{{ $labelSelected ? 'true' : 'false' }}"
                                @class([
                                    'flex min-h-10 items-center justify-between gap-3 border px-3 text-left text-xs font-bold transition',
                                    'border-primary bg-primary/10 text-primary' => $labelSelected,
                                    'border-base-300 text-base-content/60 hover:bg-base-200' => ! $labelSelected,
                                ])
                            >
                                <span class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full {{ $labelDotClasses }}"></span>
                                    {{ $label }}
                                </span>
                                <x-mary-icon :name="$labelSelected ? 'o-check' : 'o-plus'" class="h-4 w-4" />
                            </button>
                        @endforeach
                    </div>

                    <form wire:submit="addLabelToSelected" class="mt-4 border-t border-base-300 pt-4">
                        <label for="new-label" class="mb-2 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Create label</label>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <input id="new-label" wire:model="newLabelName" type="text" class="admin-control min-w-0 flex-1" placeholder="e.g. Customer follow-up">
                            <select wire:model="newLabelTone" class="admin-control sm:w-28" aria-label="Label tone">
                                <option value="info">Blue</option>
                                <option value="success">Green</option>
                                <option value="warning">Amber</option>
                                <option value="danger">Red</option>
                                <option value="neutral">Neutral</option>
                            </select>
                        </div>
                        <x-input-error :messages="$errors->get('newLabelName')" class="mt-1.5" />
                        <button type="submit" class="admin-secondary-button mt-3">Add label</button>
                    </form>
                </section>

                <section class="border-t border-base-300 pt-5">
                    <label for="record-notes" class="block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Notes</label>
                    <textarea
                        id="record-notes"
                        wire:change="updateSelectedField('notes', $event.target.value)"
                        rows="4"
                        class="mt-2 w-full rounded-md border border-base-300 bg-base-100 px-3 py-2 text-sm leading-6 text-base-content outline-none transition placeholder:text-base-content/35 focus:border-primary focus:ring-2 focus:ring-primary/15"
                        placeholder="Add notes for this record"
                    >{{ $selectedRecord['notes'] ?? '' }}</textarea>
                </section>
            </div>
        @else
            <div class="py-12 text-center text-sm text-base-content/55">Select a record to view its details.</div>
        @endif
    </x-admin.drawer>

    @php
        $selectionColumn = collect($columns)->firstWhere('key', $selectionField);
    @endphp
    <x-admin.modal
        name="add-selection"
        title="Add selection or label"
        description="Add a new option to {{ $selectionColumn['label'] ?? 'this selection field' }} and assign it to the current record."
        size="sm"
    >
        <form wire:submit="addSelectionOption" class="space-y-4">
            <div>
                <label for="selection-name" class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Selection label</label>
                <input id="selection-name" wire:model="selectionName" type="text" class="admin-control w-full" placeholder="e.g. Awaiting parts" autofocus>
                <x-input-error :messages="$errors->get('selectionName')" class="mt-1.5" />
            </div>

            <p class="text-xs leading-5 text-base-content/55">This option will be available in this field for future records and will be selected on the current record.</p>

            <div class="flex items-center justify-end gap-2 border-t border-base-300 pt-4">
                <button type="button" x-on:click="$dispatch('close-modal', { name: 'add-selection' })" class="admin-secondary-button">Cancel</button>
                <button type="submit" class="admin-primary-button">Add selection</button>
            </div>
        </form>
    </x-admin.modal>
</div>
