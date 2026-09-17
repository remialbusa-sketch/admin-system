<div class="min-h-screen bg-base-200">
    <x-admin.page-header eyebrow="Import" title="Import {{ $title }}" description="Workbook wizard runs in this lightweight tab so large files do not share the grid's Livewire snapshot. When the import finishes, return to the original tab.">
        <x-slot name="actions">
            <a href="{{ $backUrl }}" class="admin-secondary-button">
                <x-mary-icon name="o-arrow-left" class="h-4 w-4" />
                Back to table
            </a>
            <button type="button" onclick="window.close()" class="admin-secondary-button">
                Close tab
            </button>
        </x-slot>
    </x-admin.page-header>

    <div class="mx-auto max-w-5xl px-6 py-6" x-data="importUploader({ url: @js(route('import.upload-stream')) })">
        <div class="admin-surface overflow-hidden">

            {{-- Step pills --}}
            @php $importStep = $importResult ? 3 : ($importPreview ? 2 : 1); @endphp
            <div class="flex items-center gap-2 border-b border-base-300 px-6 py-4">
                @foreach ([1 => 'Source file', 2 => 'Map columns', 3 => 'Import'] as $i => $label)
                    <span class="flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full border text-[11px] font-bold tabular-nums {{ $i < $importStep ? 'border-primary bg-primary text-primary-content' : ($i === $importStep ? 'border-primary text-primary' : 'border-base-300 text-base-content/40') }}">
                            @if ($i < $importStep) <x-mary-icon name="o-check" class="h-3.5 w-3.5" /> @else {{ $i }} @endif
                        </span>
                        <span class="text-[11px] font-bold uppercase tracking-[0.1em] {{ $i === $importStep ? 'text-base-content' : 'text-base-content/45' }}">{{ $label }}</span>
                    </span>
                    @if ($i < 3) <span class="h-px w-5 bg-base-300" aria-hidden="true"></span> @endif
                @endforeach
                <span class="ml-auto text-[11px] text-base-content/45">Table: <span class="font-mono font-bold">{{ $tableKey }}</span></span>
            </div>

            <div class="space-y-6 px-6 py-6">
                {{-- Result banner (shown after import) --}}
                @if ($importResult)
                    <div class="rounded-md border p-4 text-sm {{ ($importResult['failed'] ?? 0) > 0 || $importResult['status'] === 'failed' ? 'border-error/30 bg-error/10 text-error' : 'border-success/30 bg-success/10 text-success' }}">
                        <p class="font-semibold">Import {{ str_replace('_', ' ', $importResult['status']) }}.</p>
                        <p class="mt-0.5 text-xs opacity-80">{{ number_format($importResult['processed']) }} processed, {{ number_format($importResult['failed']) }} failed &middot; batch #{{ $lastImportId }}</p>
                        <div class="mt-3 flex gap-2">
                            <a href="{{ $backUrl }}" class="admin-primary-button">Back to table</a>
                            <button type="button" wire:click="resetImportWizard" class="admin-secondary-button">Import another file</button>
                        </div>
                    </div>
                @endif

                {{-- Upload area --}}
                @if (! $importResult)
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Excel / CSV file</label>
                    <input type="file" x-on:change="uploadImportFile($el.files[0])" x-bind:disabled="uploading" class="admin-control w-full" accept=".xlsx,.xls,.csv,.txt">
                    <span class="mt-1 block text-xs font-semibold text-primary" x-show="uploading" x-cloak>Uploading... <span x-text="progress"></span>%</span>
                    <span class="mt-1 block text-xs text-error" x-show="uploadError" x-cloak x-text="uploadError"></span>
                    <x-input-error :messages="$errors->get('importFile')" class="mt-1.5" />
                    <span class="mt-1 block text-xs text-base-content/50" wire:loading wire:target="analyzeStreamedImport">Analyzing workbook...</span>
                    <span class="mt-1 block text-xs text-base-content/50">The file streams in small chunks, so PHP's upload limits don't apply (512 MB cap) — and nothing is imported until you confirm the column mapping below.</span>
                </div>
                @endif

                {{-- Preview (header row / data start + sheet picker + sample grid) --}}
                @if ($importPreview && ! $importResult)
                    @if (count($importAnalysis['sheets'] ?? []) > 1)
                        <div class="max-w-xs">
                            <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Sheet</label>
                            <select wire:model.live="importSheet" class="admin-control w-full">
                                @foreach ($importAnalysis['sheets'] as $opt)
                                    <option value="{{ $opt['name'] }}">{{ $opt['name'] }}</option>
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
                                    @foreach (array_slice($importPreview['columns'], 0, 12) as $col)
                                        <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-[0.08em] text-base-content/45">{{ $col['letter'] }}</th>
                                    @endforeach
                                </tr>
                                <tr class="border-b border-base-300">
                                    <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-[0.08em] text-primary">Header</th>
                                    @foreach (array_slice($importPreview['columns'], 0, 12) as $col)
                                        <th class="max-w-[150px] truncate px-3 py-2 font-semibold text-base-content" title="{{ $col['label'] ?? '(untitled)' }}">{{ $col['label'] ?? '(untitled)' }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-base-300">
                                @forelse ($importPreview['sampleRows'] as $row)
                                    <tr class="hover:bg-base-200/50">
                                        <td class="px-3 py-1.5 tabular-nums text-base-content/40">{{ $row['rowNumber'] }}</td>
                                        @foreach (array_slice($importPreview['columns'], 0, 12) as $col)
                                            <td class="max-w-[150px] truncate px-3 py-1.5 text-base-content/80" title="{{ $row['cells'][$col['letter']] ?? '' }}">{{ $row['cells'][$col['letter']] ?? '' }}</td>
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

                {{-- Mapping --}}
                @if ($importPreview && ! $importResult)
                    <div class="rounded-md border border-base-300">
                        <div class="flex items-center justify-between border-b border-base-300 bg-base-200/40 px-4 py-3">
                            <p class="text-xs font-bold uppercase tracking-[0.08em] text-base-content/60">Map columns — {{ $importTargets['label'] ?? $title }} — {{ $importSheet ?: 'the file' }}</p>
                            <span class="rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.08em] text-primary tabular-nums">{{ $importMappedCount }} / {{ count($importTargets['fields'] ?? []) }} fields mapped</span>
                        </div>
                        @foreach ($importMissingRequired as $label)
                            <span class="m-2 inline-flex rounded-full bg-error/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.08em] text-error">Required: {{ $label }}</span>
                        @endforeach
                        <div class="max-h-[46vh] divide-y divide-base-300 overflow-y-auto">
                            @foreach ($importTargets['fields'] ?? [] as $field)
                                @php
                                    $mappedCol = collect($importPreview['columns'] ?? [])->firstWhere('letter', $importMapping[$field['key']] ?? '');
                                    $samples = collect($mappedCol['samples'] ?? [])->filter()->take(2)->implode(' | ');
                                @endphp
                                <div class="grid grid-cols-1 items-center gap-2 px-4 py-2.5 sm:grid-cols-[minmax(0,200px)_minmax(0,1fr)_minmax(0,200px)] sm:gap-3 {{ $field['required'] && ($importMapping[$field['key']] ?? '') === '' ? 'bg-error/5' : '' }}">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-base-content">
                                            {{ $field['label'] }}
                                            @if ($field['required']) <span class="ml-0.5 text-error">*</span> @endif
                                        </p>
                                        <p class="text-[11px] uppercase tracking-[0.06em] text-base-content/45">{{ $field['kind'] }}</p>
                                    </div>
                                    <select wire:model.live="importMapping.{{ $field['key'] }}" class="admin-control w-full font-mono text-xs">
                                        <option value="">-- not mapped --</option>
                                        @foreach ($importPreview['columns'] ?? [] as $col)
                                            <option value="{{ $col['letter'] }}">{{ $col['letter'] }} &middot; {{ $col['label'] ?? '(untitled)' }}</option>
                                        @endforeach
                                    </select>
                                    <p class="hidden truncate text-xs text-base-content/50 sm:block" title="{{ $samples }}">@if ($samples !== '') e.g. {{ $samples }} @else -- @endif</p>
                                </div>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('importMapping')" class="px-4 py-2" />
                        <div class="flex items-center justify-between border-t border-base-300 px-4 py-3">
                            <span class="text-xs text-base-content/50">Only mapped columns are imported; existing records are updated by stable identifiers.</span>
                            <button type="button" wire:click="executeMappedImport" class="admin-primary-button" wire:loading.attr="disabled" wire:target="executeMappedImport">
                                <span wire:loading.remove wire:target="executeMappedImport">Start import ({{ number_format($importPreview['totalRows'] ?? 0) }} rows)</span>
                                <span wire:loading wire:target="executeMappedImport">Importing...</span>
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <p class="mt-4 text-center text-xs text-base-content/40">
            Opened in a new tab to avoid the grid's Livewire snapshot. After a successful import, <a href="{{ $backUrl }}" class="link link-primary">return to the table</a> or close this tab — the original tab's grid will pick up new rows on reload.
        </p>
    </div>
</div>
