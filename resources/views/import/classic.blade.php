<x-layouts.dashboard title="Import {{ $title }}">
    <x-admin.page-header eyebrow="Import" title="Import {{ $title }}" description="Upload your workbook, pick your first row and first column, then match every column to its type before importing. Importing replaces the table's records — undo stays available on the table page.">
        <x-slot name="actions">
            <a href="{{ $backUrl }}" class="admin-secondary-button">
                <x-mary-icon name="o-arrow-left" class="h-4 w-4" />
                Back to table
            </a>
            <button type="button" onclick="window.close()" class="admin-secondary-button">Close tab</button>
        </x-slot>
    </x-admin.page-header>

    <div class="mx-auto max-w-5xl px-6 py-6"
         x-data="classicImporter({
             tableKey: @js($tableKey),
             analyzeUrl: @js(route('tables.import.classic.analyze', $tableKey)),
             executeUrl: @js(route('tables.import.classic.execute', $tableKey)),
             failedRowsUrlTemplate: @js(route('tables.import.classic.failed-rows', ['table' => $tableKey, 'batch' => '__BATCH__'])),
             uploadUrl: @js(route('import.upload-stream')),
             uploadChunkUrl: @js(route('import.upload-chunk')),
             targets: @js($targets),
             isDynamic: @js($isDynamic ?? false),
             columnTypes: @js($columnTypes ?? []),
             columnTypeHelp: @js($columnTypeHelp ?? []),
             optionPalette: @js($optionPalette ?? []),
             optionLimit: @js($optionLimit ?? 200)
         })"
         x-init="init()"
         @click.window="onWindowClick($event)"
         @keydown.escape.window="typeMenu = null, optionsMenu = null">

        <div class="admin-surface overflow-hidden">
            <div class="flex items-center gap-2 border-b border-base-300 px-6 py-4">
                <template x-for="(label, idx) in stepLabels()" :key="idx">
                    <span class="flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full border text-[11px] font-bold tabular-nums"
                              :class="step > idx+1 ? 'border-primary bg-primary text-primary-content' : (step === idx+1 ? 'border-primary text-primary' : 'border-base-300 text-base-content/40')">
                            <template x-if="step > idx+1"><span><x-mary-icon name="o-check" class="h-3.5 w-3.5" /></span></template>
                            <template x-if="step <= idx+1"><span x-text="idx+1"></span></template>
                        </span>
                        <span class="text-[11px] font-bold uppercase tracking-[0.1em]" :class="step === idx+1 ? 'text-base-content' : 'text-base-content/45'" x-text="label"></span>
                        <span x-show="idx < 2" class="h-px w-5 bg-base-300" aria-hidden="true"></span>
                    </span>
                </template>
                <span class="ml-auto text-[11px] text-base-content/45">Table: <span class="font-mono font-bold" x-text="tableKey"></span></span>
            </div>

            <div class="space-y-6 px-6 py-6">
                {{-- Global error / result --}}
                <div x-show="globalError" x-cloak class="rounded-md border border-error/30 bg-error/10 p-3 text-sm text-error" x-text="globalError"></div>

                <div x-show="result" x-cloak class="rounded-md border p-4 text-sm" :class="(result?.failed > 0 || result?.status === 'failed') ? 'border-warning/40 bg-warning/10 text-warning-content' : 'border-success/30 bg-success/10 text-success'">
                    <p class="font-semibold" x-text="result ? `Import ${result.status.replaceAll('_',' ')}.` : ''"></p>
                    <p class="mt-0.5 text-xs opacity-80" x-text="result ? `${Number(result.processed).toLocaleString()} processed, ${Number(result.failed).toLocaleString()} failed · batch #${result.batchId}` : ''"></p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <a :href="backUrl" class="admin-primary-button">Back to table</a>
                        <button type="button" @click="reset()" class="admin-secondary-button">Import another file</button>
                        <a x-show="result && result.failed > 0" x-cloak :href="failedRowsUrl()" class="admin-secondary-button">
                            <x-mary-icon name="o-arrow-down-tray" class="h-4 w-4" />
                            Download failed rows (CSV)
                        </a>
                    </div>
                </div>

                {{-- Step 1: source file (upload is step-1 content) --}}
                <div x-show="!result && phase === 'header'" x-cloak>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Excel / CSV file</label>
                    <input type="file" @change="onFile($el.files[0])" :disabled="uploading || analyzing" class="admin-control w-full" accept=".xlsx,.xls,.csv,.txt">
                    <span class="mt-1 block text-xs font-semibold text-primary" x-show="uploading" x-cloak>Uploading... <span x-text="progress"></span>%</span>
                    <span class="mt-1 block text-xs font-semibold text-primary" x-show="analyzing" x-cloak>Analyzing workbook...</span>
                    <span class="mt-1 block text-xs text-base-content/50">
                        The file streams in 1 MB chunks (<span class="font-mono">POST /import/upload-chunk</span>, firewall-safe with a
                        <span class="font-mono">PUT</span> fallback) — large workbooks are fine, and column mapping is auto-suggested from the headers.
                    </span>
                </div>

                {{-- Step 1: "What is your first row?" picker, then mapping --}}
                <div x-show="preview && !result && phase === 'header'" x-cloak class="space-y-4">
                    <div>
                        <h3 class="text-lg font-bold text-base-content">What is your first row?</h3>
                        <p class="mt-0.5 text-xs text-base-content/55">This will become the column titles. Click a row to select it.</p>
                    </div>

                    <div x-show="analysis && analysis.sheets && analysis.sheets.length >= 1" class="max-w-xs">
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Sheet</label>
                        <select x-model="sheet" @change="sheetChanged()" :disabled="(analysis?.sheets || []).length < 2" class="admin-control w-full">
                            <template x-for="s in (analysis?.sheets || [])" :key="s.name">
                                <option :value="s.name" x-text="s.name"></option>
                            </template>
                        </select>
                    </div>

                    <div class="overflow-x-auto rounded-md border border-base-300">
                        <table class="min-w-full border-collapse text-left text-xs">
                            <thead>
                                <tr class="border-b border-base-300 bg-base-200/60">
                                    <th class="w-12 px-3 py-2"></th>
                                    <template x-for="col in (preview?.columns || []).slice(0,12)" :key="col.letter">
                                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-[0.08em] text-base-content/45" x-text="col.letter"></th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-base-300">
                                <template x-for="row in (preview?.topRows || [])" :key="row.rowNumber">
                                    <tr @click="pickHeaderRow(row.rowNumber)" class="cursor-pointer transition-colors hover:bg-base-200/50" :class="row.rowNumber === headerRow ? 'bg-primary/15 hover:bg-primary/20' : ''">
                                        <td class="px-3 py-1.5 tabular-nums text-base-content/40" x-text="row.rowNumber"></td>
                                        <template x-for="col in (preview?.columns || []).slice(0,12)" :key="col.letter">
                                            <td class="max-w-[150px] truncate px-3 py-1.5" :class="row.rowNumber === headerRow ? 'font-semibold text-base-content' : 'text-base-content/70'" :title="row.cells[col.letter] || ''" x-text="row.cells[col.letter] || ''"></td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">First data row</label>
                            <input type="number" :min="headerRow+1" x-model.number="dataStart" class="admin-control w-24 tabular-nums">
                        </div>
                        <div class="flex flex-wrap gap-2 text-[11px] font-bold uppercase tracking-[0.08em]">
                            <span class="rounded-full bg-primary/10 px-3 py-1 text-primary tabular-nums" x-text="`Header: row ${headerRow}`"></span>
                            <span class="rounded-full bg-base-200 px-3 py-1 text-base-content/60 tabular-nums" x-text="`${preview?.totalColumns || 0} columns`"></span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-base-300 pt-4">
                        <span class="text-xs text-base-content/50" x-text="`File: ${originalName || ''}`"></span>
                        <button type="button" @click="goMap()" :disabled="analyzing" class="admin-primary-button">Next</button>
                    </div>
                </div>

                {{-- Step 2: title column — "What is your first column?" --}}
                <div x-show="preview && !result && phase === 'title'" x-cloak class="space-y-4">
                    <div>
                        <h3 class="text-lg font-bold text-base-content">What is your first column?</h3>
                        <p class="mt-0.5 text-xs text-base-content/55" x-text="titleHint()"></p>
                    </div>

                    <div class="overflow-x-auto rounded-md border border-base-300">
                        <table class="min-w-full border-collapse text-left text-xs">
                            <thead>
                                <tr class="border-b border-base-300 bg-base-200/60">
                                    <th class="w-12 px-3 py-1"></th>
                                    <template x-for="col in (preview?.columns || [])" :key="col.letter">
                                        <th class="px-1 py-1 text-center">
                                            <button type="button" @click="pickTitleColumn(col.letter)" :title="`Use column ${col.letter} as the title`" class="w-full rounded px-3 py-2 text-[10px] font-bold uppercase tracking-[0.08em] transition-colors" :class="titleColumn === col.letter ? 'bg-primary text-primary-content' : 'text-base-content/45 hover:bg-primary/10 hover:text-primary'" x-text="col.letter"></button>
                                        </th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-base-300">
                                <template x-for="row in (preview?.topRows || []).slice(0,6)" :key="row.rowNumber">
                                    <tr class="hover:bg-base-200/50">
                                        <td class="px-3 py-1.5 tabular-nums text-base-content/40" x-text="row.rowNumber"></td>
                                        <template x-for="col in (preview?.columns || [])" :key="col.letter">
                                            <td class="max-w-[150px] truncate px-3 py-1.5" :class="titleColumn === col.letter ? 'bg-primary/15 font-semibold text-base-content' : 'text-base-content/70'" :title="row.cells[col.letter] || ''" x-text="row.cells[col.letter] || ''"></td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-base-300 pt-4">
                        <span class="text-xs text-base-content/50" x-text="titleColumn ? `First column: ${titleColumn}` : 'No first column selected — click a column above.'"></span>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="titleBack()" class="admin-secondary-button">Back</button>
                            <button type="button" @click="titleNext()" class="admin-primary-button">Next</button>
                        </div>
                    </div>
                </div>

                {{-- Step 3: customize each column (type + connection) over the record preview, then import --}}
                <div x-show="preview && !result && phase === 'columns'" x-cloak class="space-y-4">
                    <div>
                        <h3 class="text-lg font-bold text-base-content">Customize your columns</h3>
                        <p class="mt-0.5 text-xs text-base-content/55" x-text="isDynamic ? 'Every included column becomes a table column. Importing replaces the whole table.' : 'Connect each column to a field and match its type. Importing replaces every record.'"></p>
                    </div>

                    <div class="overflow-x-auto rounded-md border border-base-300">
                        <table class="min-w-full border-collapse text-left text-xs">
                            <thead>
                                <tr class="border-b border-base-300 bg-base-200/60">
                                    <th class="w-12 px-3 py-2"></th>
                                    <template x-for="col in (preview?.columns || [])" :key="col.letter">
                                        <th scope="col" class="min-w-[200px] max-w-[260px] px-3 py-2 align-bottom" :class="isDimmed(col) ? 'opacity-45' : ''">
                                            <div class="space-y-1.5">
                                                <button type="button" data-type-menu @click="toggleTypeMenu(col.letter, $event)"
                                                        class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-base-300 bg-base-100 px-2 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-base-content transition-colors hover:border-primary hover:text-primary"
                                                        :aria-label="`Type for column ${col.letter}`"
                                                        :aria-expanded="typeMenu?.letter === col.letter">
                                                    <span class="truncate" x-text="typeLabel(colTypes[col.letter] || 'text')"></span>
                                                    <x-mary-icon name="o-chevron-down" class="h-3 w-3 shrink-0" />
                                                </button>
                                                {{-- Option editor chip: only status/dropdown columns carry options --}}
                                                <button type="button" data-options-menu x-show="isOptionType(col.letter)" x-cloak @click="toggleOptionsMenu(col.letter, $event)"
                                                        class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-base-300 bg-base-100 px-2 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-base-content transition-colors hover:border-primary hover:text-primary"
                                                        :aria-label="`Options for column ${col.letter}`"
                                                        :aria-expanded="optionsMenu?.letter === col.letter">
                                                    <x-mary-icon name="o-adjustments-horizontal" class="h-3 w-3 shrink-0" />
                                                    <span class="truncate" x-text="optionsChipLabel(col.letter)"></span>
                                                </button>
                                                <template x-if="!isDynamic">
                                                    <select class="admin-control w-full text-xs"
                                                            :value="columnTarget(col.letter)"
                                                            @change="onColumnTarget(col.letter, $event.target.value, col.label)"
                                                            :aria-label="`Connect column ${col.letter}`">
                                                        <option value="">— Skip this column —</option>
                                                        <template x-for="field in (targets?.fields || [])" :key="field.key">
                                                            <option :value="field.key" x-text="field.label"></option>
                                                        </template>
                                                        <option value="__new__">＋ New column…</option>
                                                    </select>
                                                </template>
                                            </div>
                                        </th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-base-300">
                                {{-- Row 1: the file's own header cells --}}
                                <tr class="bg-base-200/80">
                                    <td class="px-3 py-2"></td>
                                    <template x-for="col in (preview?.columns || [])" :key="col.letter">
                                        <td class="px-3 py-2 align-top" :class="isDimmed(col) ? 'opacity-45' : ''">
                                            <template x-if="isDynamic">
                                                <div class="flex items-center gap-2">
                                                    <input type="checkbox" x-model="importCols[col.letter].include" class="checkbox checkbox-sm checkbox-primary" :disabled="col.letter === titleColumn" :aria-label="`Include column ${col.letter}`">
                                                    <span class="font-mono text-[10px] text-base-content/45" x-text="col.letter"></span>
                                                    <input type="text" x-model="importCols[col.letter].name" maxlength="100" class="admin-control w-full min-w-0 text-xs font-semibold" :aria-label="`Name for column ${col.letter}`" placeholder="Column name">
                                                    <span x-show="col.letter === titleColumn" x-cloak class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.08em] text-primary">Title</span>
                                                </div>
                                            </template>
                                            <template x-if="!isDynamic">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-mono text-[10px] text-base-content/45" x-text="col.letter"></span>
                                                    <template x-if="columnTarget(col.letter) === '__new__'">
                                                        <input type="text" x-model="newCols[col.letter].name" maxlength="100" class="admin-control w-full min-w-0 text-xs font-semibold" placeholder="Column name" :aria-label="`Name for column ${col.letter}`">
                                                    </template>
                                                    <template x-if="columnTarget(col.letter) !== '__new__'">
                                                        <span class="truncate text-xs font-semibold text-base-content" :title="col.label" x-text="col.label || '(untitled)'"></span>
                                                    </template>
                                                    <span x-show="col.letter === titleColumn" x-cloak class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.08em] text-primary">Title</span>
                                                </div>
                                            </template>
                                        </td>
                                    </template>
                                </tr>
                                {{-- The first records, as they will land --}}
                                <template x-for="row in previewRows()" :key="row.rowNumber">
                                    <tr class="hover:bg-base-200/50">
                                        <td class="px-3 py-1.5 tabular-nums text-base-content/40" x-text="row.rowNumber"></td>
                                        <template x-for="col in (preview?.columns || [])" :key="col.letter">
                                            <td class="max-w-[200px] truncate px-3 py-1.5 text-base-content/70" :class="isDimmed(col) ? 'opacity-45' : ''" :title="row.cells[col.letter] || ''" x-text="row.cells[col.letter] || ''"></td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    {{-- Type picker: name + description per type, anchored to the clicked header --}}
                    <template x-if="typeMenu">
                        <div data-type-menu
                             class="fixed z-50 max-h-[60vh] w-72 overflow-y-auto rounded-md border border-base-300 bg-base-100 p-1 shadow-xl"
                             :style="`left:${typeMenu.left}px; top:${typeMenu.top}px`">
                            <template x-for="(label, key) in (columnTypes || {})" :key="key">
                                <button type="button" @click="setColumnType(typeMenu.letter, key)"
                                        class="block w-full rounded px-3 py-2 text-left transition-colors hover:bg-base-200"
                                        :class="(colTypes[typeMenu.letter] || 'text') === key ? 'bg-primary/10' : ''">
                                    <span class="block text-xs font-bold text-base-content" x-text="label"></span>
                                    <span class="block text-[11px] text-base-content/55" x-text="(columnTypeHelp || {})[key] || ''"></span>
                                </button>
                            </template>
                            <p x-show="typeMenuNote()" x-cloak class="mt-1 border-t border-base-300 px-3 py-2 text-[11px] leading-snug text-base-content/55" x-text="typeMenuNote()"></p>
                        </div>
                    </template>

                    {{-- Option editor: rename/recolor/remove options, seeded from the file's distinct values --}}
                    <template x-if="optionsMenu">
                        <div data-options-menu
                             class="fixed z-50 w-80 rounded-md border border-base-300 bg-base-100 shadow-xl"
                             :style="`left:${optionsMenu.left}px; top:${optionsMenu.top}px`">
                            <div class="border-b border-base-300 px-3 py-2">
                                <p class="text-xs font-bold text-base-content" x-text="`Options — column ${optionsMenu.letter}`"></p>
                                <p class="mt-0.5 text-[11px] leading-snug text-base-content/55" x-text="optionHint(optionsMenu.letter)"></p>
                            </div>
                            <div class="max-h-64 space-y-1.5 overflow-y-auto px-3 py-2">
                                <template x-for="(opt, idx) in (colOptions[optionsMenu.letter] || [])" :key="idx">
                                    <div class="flex items-center gap-2">
                                        <input type="color" class="h-6 w-6 shrink-0 cursor-pointer rounded border border-base-300 bg-transparent p-0"
                                               :value="opt.color" @input="setOptionColor(optionsMenu.letter, idx, $event.target.value)"
                                               :aria-label="`Color for option ${idx + 1}`">
                                        <input type="text" class="admin-control min-w-0 flex-1 text-xs" maxlength="100" :value="opt.label"
                                               @change="renameOption(optionsMenu.letter, idx, $event)"
                                               :aria-label="`Label for option ${idx + 1}`">
                                        <button type="button" @click="removeOption(optionsMenu.letter, idx)"
                                                class="shrink-0 rounded p-1 text-base-content/40 transition-colors hover:bg-error/10 hover:text-error"
                                                :aria-label="`Remove option ${idx + 1}`">
                                            <x-mary-icon name="o-x-mark" class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </template>
                                <p x-show="colOptions[optionsMenu.letter] === undefined" x-cloak class="py-1 text-[11px] leading-snug text-base-content/45">
                                    Not customized yet — the defaults described above apply until you edit this list.
                                </p>
                                <p x-show="colOptions[optionsMenu.letter]?.length === 0" x-cloak class="py-1 text-[11px] leading-snug text-base-content/45">
                                    Empty list — values found in the file are appended automatically during import.
                                </p>
                            </div>
                            <div class="flex items-center gap-2 border-t border-base-300 px-3 py-2">
                                <input type="text" class="admin-control min-w-0 flex-1 text-xs" maxlength="100" x-model="optionDraft"
                                       @keydown.enter.prevent="addOption(optionsMenu.letter)" placeholder="New option label"
                                       aria-label="New option label">
                                <button type="button" @click="addOption(optionsMenu.letter)" class="admin-primary-button shrink-0 px-2 py-1 text-[11px]">Add</button>
                            </div>
                            <div class="flex items-center justify-between gap-2 border-t border-base-300 px-3 py-1.5">
                                <span class="text-[11px] tabular-nums text-base-content/45" x-text="`${(colOptions[optionsMenu.letter] || []).length} / ${optionLimit}`"></span>
                                <button type="button" x-show="fileValues(optionsMenu.letter).length" x-cloak
                                        @click="useFileValues(optionsMenu.letter)"
                                        class="admin-secondary-button px-2 py-1 text-[11px]"
                                        x-text="`Use file values (${fileValues(optionsMenu.letter).length})`"></button>
                            </div>
                            <p x-show="optionError" x-cloak class="border-t border-base-300 px-3 py-1.5 text-[11px] text-error" x-text="optionError"></p>
                        </div>
                    </template>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 pt-4">
                        <div class="flex min-w-0 flex-wrap items-center gap-2 text-[11px] font-bold uppercase tracking-[0.08em]">
                            <span class="max-w-[240px] truncate rounded-full bg-base-200 px-3 py-1 text-base-content/60" :title="`${originalName || ''} → ${tableKey}`" x-text="`${originalName || ''} → ${tableKey}`"></span>
                            <template x-if="isDynamic">
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-primary tabular-nums" x-text="`${importColumnsPayload().length} columns`"></span>
                            </template>
                            <template x-if="!isDynamic">
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-primary tabular-nums" x-text="`${columnsMappedCount()} connected`"></span>
                            </template>
                            <template x-if="!isDynamic && newColumnsPayload().length > 0">
                                <span class="rounded-full bg-base-200 px-3 py-1 text-base-content/60 tabular-nums" x-text="`${newColumnsPayload().length} new`"></span>
                            </template>
                            <template x-if="!isDynamic">
                                <template x-for="label in columnsMissingRequired()" :key="label">
                                    <span class="rounded-full bg-error/10 px-3 py-1 text-error" x-text="`Required: ${label}`"></span>
                                </template>
                            </template>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="columnsBack()" class="admin-secondary-button">Back</button>
                            <button type="button" @click="execute()" :disabled="executing || (isDynamic ? importColumnsPayload().length === 0 : columnsMissingRequired().length > 0)" class="admin-primary-button">
                                <span x-show="!executing" x-text="`Import ${Number(preview?.totalRows || 0).toLocaleString()} records`"></span>
                                <span x-show="executing">Importing...</span>
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <p class="mt-4 text-center text-xs text-base-content/40">
            This wizard never calls <span class="font-mono">/livewire/update</span>. If something fails, the message above comes from a plain JSON response and
            <span class="font-mono">storage/logs/laravel.log</span> holds the matching <span class="font-mono">classicImport.*</span> breadcrumbs.
        </p>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('classicImporter', ({ tableKey, analyzeUrl, executeUrl, failedRowsUrlTemplate, uploadUrl, uploadChunkUrl, targets, isDynamic, columnTypes, columnTypeHelp, optionPalette, optionLimit }) => ({
                tableKey, analyzeUrl, executeUrl, failedRowsUrlTemplate, uploadUrl, uploadChunkUrl, targets, isDynamic, columnTypes, columnTypeHelp, optionPalette, optionLimit,
                backUrl: @js($backUrl),
                step: 1,
                phase: 'header',
                titleColumn: '',
                importCols: {},
                newCols: {},
                newColsSeeded: false,
                colTypes: {},
                colTypesSeeded: false,
                seededSignature: null,
                typeMenu: null,
                colOptions: {},
                optionsMenu: null,
                optionDraft: '',
                optionError: '',
                uploading: false,
                analyzing: false,
                executing: false,
                progress: 0,
                globalError: '',
                uploadId: null,
                originalName: null,
                analysis: null,
                preview: null,
                sheet: '',
                headerRow: 1,
                dataStart: 2,
                mapping: {},
                mappingSource: {},
                result: null,

                init() {
                    (targets?.fields || []).forEach((field) => {
                        this.mapping[field.key] = '';
                    });
                },

                /**
                 * Parse a fetch response as JSON. A server error page (HTML)
                 * would otherwise surface as the cryptic
                 * "Unexpected token '<'" — turn it into an actionable message
                 * that carries the HTTP status.
                 */
                async readJson(resp) {
                    const text = await resp.text();
                    try {
                        return JSON.parse(text);
                    } catch {
                        if (resp.status === 419) {
                            throw new Error('Your session expired. Refresh this page, sign in again, then retry the import.');
                        }
                        throw new Error(text.trimStart().startsWith('<')
                            ? `The server returned an HTML error page (HTTP ${resp.status}) instead of JSON — check storage/logs/laravel.log on the server for the real error.`
                            : `The server returned an unreadable response (HTTP ${resp.status}).`);
                    }
                },

                async onFile(file) {
                    this.globalError = '';
                    this.result = null;
                    // Fresh file, fresh mapping: stale letters from a previous
                    // workbook must never leak into this one.
                    this.mapping = {};
                    this.mappingSource = {};
                    this.titleColumn = '';
                    this.importCols = {};
                    this.newCols = {};
                    this.newColsSeeded = false;
                    this.colTypes = {};
                    this.colTypesSeeded = false;
                    this.seededSignature = null;
                    this.typeMenu = null;
                    this.colOptions = {};
                    this.optionsMenu = null;
                    this.optionDraft = '';
                    this.optionError = '';
                    if (!file) return;
                    const ext = (file.name.split('.').pop() || '').toLowerCase();
                    if (!['xlsx','xls','csv','txt'].includes(ext)) {
                        this.globalError = 'Unsupported file type (.' + ext + '). Use .xlsx, .xls or .csv.';
                        return;
                    }
                    this.uploading = true;
                    this.progress = 0;
                    this.uploadId = null;
                    this.originalName = file.name;
                    try {
                        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const bytes = new Uint8Array(16);
                        crypto.getRandomValues(bytes);
                        const uploadId = Array.from(bytes).map(b => b.toString(16).padStart(2,'0')).join('');
                        this.uploadId = uploadId;
                        // Primary: POST multipart chunk (1 MB, under cPanel's post_max_size and ModSecurity-safe).
                        const chunkSize = 1 * 1024 * 1024;
                        let offset = 0;
                        let usePost = true;
                        while (offset < file.size) {
                            const slice = file.slice(offset, offset + chunkSize);
                            let resp;
                            if (usePost) {
                                const form = new FormData();
                                form.append('chunk', slice, file.name);
                                form.append('uploadId', uploadId);
                                form.append('offset', String(offset));
                                form.append('fileName', file.name);
                                resp = await fetch(uploadChunkUrl, {
                                    method: 'POST',
                                    headers: {
                                        'X-CSRF-TOKEN': csrf,
                                        'X-Requested-With': 'XMLHttpRequest',
                                    },
                                    body: form,
                                });
                                if (resp.status === 404 || resp.status === 405) {
                                    usePost = false;
                                }
                            }
                            if (!usePost) {
                                resp = await fetch(uploadUrl, {
                                    method: 'PUT',
                                    headers: {
                                        'X-CSRF-TOKEN': csrf,
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'X-File-Name': encodeURIComponent(file.name),
                                        'X-Upload-Id': uploadId,
                                        'X-File-Offset': String(offset),
                                        'Content-Type': 'application/octet-stream',
                                    },
                                    body: slice,
                                });
                            }
                            if (!resp.ok) {
                                let detail = await resp.text();
                                try { const p = JSON.parse(detail); if (p?.message) detail = p.message; } catch { if (detail.trimStart().startsWith('<')) detail = `Upload blocked by web firewall (HTTP ${resp.status}). Tried ${usePost ? 'POST' : 'PUT'} — ask the host to allow it.`; }
                                throw new Error(detail.slice(0,320) || 'Upload failed at byte ' + offset + '.');
                            }
                            offset = Math.min(offset + chunkSize, file.size);
                            this.progress = Math.round(offset / file.size * 100);
                        }
                        await this.analyze();
                    } catch (e) {
                        this.globalError = e.message || 'The upload failed.';
                    } finally {
                        this.uploading = false;
                    }
                },

                async analyze() {
                    this.globalError = '';
                    this.analyzing = true;
                    try {
                        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const resp = await fetch(analyzeUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({
                                uploadId: this.uploadId,
                                originalName: this.originalName,
                                sheet: this.sheet || null,
                                headerRow: this.headerRow || null,
                                dataStart: this.dataStart || null,
                            }),
                        });
                        const data = await this.readJson(resp);
                        if (!resp.ok) throw new Error(data.message || 'Analysis failed.');
                        this.analysis = data.analysis;
                        this.preview = data.preview;
                        this.sheet = this.preview.sheet || this.sheet;
                        this.headerRow = this.preview.headerRow || 1;
                        this.dataStart = this.preview.dataStart || this.headerRow + 1;
                        this.phase = 'header';
                        this.seedMapping(data.recalled || {}, data.suggested || {});
                    } catch (e) {
                        this.globalError = e.message || 'The workbook could not be analyzed.';
                        throw e;
                    } finally {
                        this.analyzing = false;
                    }
                },

                /** Pre-fill the mapping: last-used values win, auto-suggestions fill the rest. */
                seedMapping(recalled, suggested) {
                    (targets?.fields || []).forEach((field) => {
                        if (!(field.key in this.mapping)) {
                            this.mapping[field.key] = '';
                        }
                    });

                    Object.entries(recalled).forEach(([key, letter]) => {
                        if (this.mapping[key] === '' && letter) {
                            this.mapping[key] = letter;
                            this.mappingSource[key] = 'recalled';
                        }
                    });

                    Object.entries(suggested).forEach(([key, letter]) => {
                        if (this.mapping[key] === '' && letter) {
                            this.mapping[key] = letter;
                            this.mappingSource[key] = 'auto';
                        }
                    });
                },

                /** Sheet switch: the new sheet gets a clean mapping slate. */
                async sheetChanged() {
                    this.mapping = {};
                    this.mappingSource = {};
                    this.titleColumn = '';
                    this.importCols = {};
                    this.newCols = {};
                    this.newColsSeeded = false;
                    this.colTypes = {};
                    this.colTypesSeeded = false;
                    this.seededSignature = null;
                    this.typeMenu = null;
                    this.colOptions = {};
                    this.optionsMenu = null;
                    this.optionDraft = '';
                    this.optionError = '';
                    await this.reAnalyze();
                },

                async reAnalyze() {
                    if (!this.preview) return;
                    try { await this.analyze(); } catch {}
                },

                /** Header row picked by clicking a preview row — local only. */
                pickHeaderRow(rowNumber) {
                    this.headerRow = rowNumber;
                    if (!this.dataStart || this.dataStart <= rowNumber) {
                        this.dataStart = rowNumber + 1;
                    }
                },

                /** Next: analyze with the chosen rows, then the title step. */
                async goMap() {
                    try {
                        await this.analyze();
                        // Reseed defaults only when the preview's letters+labels
                        // actually changed: back/forward must keep step-3 type
                        // picks, renames and includes.
                        const signature = JSON.stringify((this.preview?.columns || []).map((col) => [col.letter, col.label || '']));
                        const previewChanged = signature !== this.seededSignature;
                        this.seededSignature = signature;
                        if (this.isDynamic) {
                            this.titleColumn = this.mapping['__identity__'] || this.mapping['name'] || this.mapping[this.titleFieldKey()] || this.titleColumn || '';
                            if (previewChanged) {
                                this.colTypesSeeded = false;
                                this.initImportCols();
                            }
                        } else {
                            if (previewChanged) {
                                this.colTypesSeeded = false;
                                this.newColsSeeded = false;
                            }
                            this.initNewCols();
                        }
                        this.initColTypes();
                        this.ensureTitleColumn();
                        this.phase = 'title';
                        this.step = 2;
                    } catch {}
                },

                /** Default include/name per file column for a replacing import. */
                initImportCols() {
                    this.importCols = {};
                    (this.preview?.columns || []).forEach((col) => {
                        const header = (col.label || '').trim();
                        this.importCols[col.letter] = {
                            include: header !== '',
                            name: header,
                        };
                    });
                },

                normalizeLabel(label) {
                    return (label || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
                },

                /**
                 * Single source of truth for the header type buttons: the kind
                 * of the field the column feeds (via recall/auto-map), else the
                 * header name match, else text. Seeded once per file so the
                 * user's step-3 picks survive back-and-forth navigation.
                 */
                initColTypes() {
                    if (this.colTypesSeeded) return;
                    // Reseeding for a changed preview: option drafts keyed by
                    // letter belong to the old preview and must not leak.
                    this.colOptions = {};
                    this.optionsMenu = null;
                    this.optionDraft = '';
                    this.optionError = '';
                    const kindByNorm = {};
                    (targets?.fields || []).forEach((field) => {
                        kindByNorm[this.normalizeLabel(field.label)] = field.kind;
                    });
                    this.colTypes = {};
                    (this.preview?.columns || []).forEach((col) => {
                        const mappedKey = Object.entries(this.mapping || {}).find(([, letter]) => letter === col.letter)?.[0];
                        const field = mappedKey ? (targets?.fields || []).find((entry) => entry.key === mappedKey) : null;
                        this.colTypes[col.letter] = (field && field.kind) || kindByNorm[this.normalizeLabel((col.label || '').trim())] || 'text';
                    });
                    this.colTypesSeeded = true;
                },

                /** This table's title field (identity for dynamic tables). */
                titleFieldKey() {
                    if (this.isDynamic) {
                        return '__identity__';
                    }
                    return (targets && targets.title) || '';
                },

                titleHint() {
                    const field = (targets?.fields || []).find((entry) => entry.key === this.titleFieldKey());
                    const name = field ? field.label : 'each row';
                    return this.isDynamic
                        ? 'This will become the title of each row. Click a column to select it.'
                        : `This will become the ${name} of each row. Click a column to select it.`;
                },

                /**
                 * Title pick: identity for dynamic rows, the title field for
                 * managed ones. The letter is released from every other target
                 * first, so no column can ever feed two fields.
                 */
                pickTitleColumn(letter) {
                    const key = this.titleFieldKey();
                    if (!key) {
                        return;
                    }
                    this.titleColumn = letter;
                    if (this.isDynamic) {
                        if (this.importCols[letter]) {
                            this.importCols[letter].include = true;
                        }
                        return;
                    }
                    Object.keys(this.mapping).forEach((mapKey) => {
                        if (mapKey !== key && this.mapping[mapKey] === letter) {
                            this.mapping[mapKey] = '';
                            delete this.mappingSource[mapKey];
                        }
                    });
                    delete this.newCols[letter];
                    this.mapping[key] = letter;
                    this.mappingSource[key] = 'manual';
                    const field = (targets?.fields || []).find((entry) => entry.key === key);
                    if (field && field.kind) {
                        this.colTypes[letter] = field.kind;
                    }
                },

                /** Dynamic: the title must be one of the imported columns. */
                ensureTitleColumn() {
                    if (!this.isDynamic) return;
                    const letters = (this.preview?.columns || []).map((col) => col.letter);
                    if (!letters.includes(this.titleColumn)) {
                        const fallback = this.importColumnsPayload()[0]?.letter || letters[0] || '';
                        this.titleColumn = fallback;
                        if (fallback && this.importCols[fallback]) {
                            this.importCols[fallback].include = true;
                        }
                    }
                },

                stepLabels() {
                    return ['First row', 'First column', 'Customize columns'];
                },

                columnsBack() {
                    this.phase = 'title';
                    this.step = 2;
                },

                titleBack() {
                    this.phase = 'header';
                    this.step = 1;
                },

                /** Step 2 -> step 3: the customization table. */
                titleNext() {
                    this.ensureTitleColumn();
                    this.phase = 'columns';
                    this.step = 3;
                },

                columnsMappedCount() {
                    if (this.isDynamic) {
                        return this.importColumnsPayload().length;
                    }
                    return Object.values(this.mapping || {}).filter((letter) => letter !== '').length;
                },

                columnsMissingRequired() {
                    const mappedKeys = new Set(
                        Object.entries(this.mapping || {}).filter(([, letter]) => letter !== '').map(([key]) => key),
                    );
                    return (targets?.fields || []).filter((field) => field.required && !mappedKeys.has(field.key)).map((field) => field.label);
                },

                /** Type buttons: one open menu at a time, anchored to its header. */
                toggleTypeMenu(letter, event) {
                    this.optionsMenu = null;
                    if (this.typeMenu && this.typeMenu.letter === letter) {
                        this.typeMenu = null;
                        return;
                    }
                    const rect = event.currentTarget.getBoundingClientRect();
                    const width = 288; // w-72
                    this.typeMenu = {
                        letter,
                        left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)),
                        top: Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - 260)),
                    };
                },

                /** Option editor chip: mutually exclusive with the type menu. */
                toggleOptionsMenu(letter, event) {
                    this.typeMenu = null;
                    if (this.optionsMenu && this.optionsMenu.letter === letter) {
                        this.optionsMenu = null;
                        return;
                    }
                    this.optionDraft = '';
                    this.optionError = '';
                    const rect = event.currentTarget.getBoundingClientRect();
                    const width = 320; // w-80
                    this.optionsMenu = {
                        letter,
                        left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)),
                        top: Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - 360)),
                    };
                },

                /** Any click outside the buttons/panel dismisses the open menu. */
                onWindowClick(event) {
                    const target = event.target;
                    if (target && target.closest) {
                        if (target.closest('[data-type-menu]') || target.closest('[data-options-menu]')) return;
                    }
                    this.typeMenu = null;
                    this.optionsMenu = null;
                },

                setColumnType(letter, key) {
                    if (letter) this.colTypes[letter] = key;
                    this.typeMenu = null;
                },

                /** Only status/dropdown columns carry an option list. */
                isOptionType(letter) {
                    return ['status', 'dropdown'].includes(this.colTypes[letter] || 'text');
                },

                /** Header chip label: the list length once customized. */
                optionsChipLabel(letter) {
                    const list = this.colOptions[letter];
                    return Array.isArray(list) ? `Options · ${list.length}` : 'Options';
                },

                /** Distinct non-blank values the preview scanned for this column. */
                fileValues(letter) {
                    const col = (this.preview?.columns || []).find((c) => c.letter === letter);
                    return col?.distinct || [];
                },

                /** Replace the list with the file's distinct values (palette-cycled colors). */
                useFileValues(letter) {
                    const values = this.fileValues(letter);
                    if (!values.length) return;
                    const palette = this.optionPalette?.length ? this.optionPalette : ['#64748B'];
                    this.colOptions[letter] = values.map((label, i) => ({
                        label,
                        color: palette[i % palette.length],
                    }));
                    this.optionError = '';
                },

                /** Append the drafted label (creating the list on first edit). */
                addOption(letter) {
                    const label = (this.optionDraft || '').trim();
                    if (!label) return;
                    if (!Array.isArray(this.colOptions[letter])) this.colOptions[letter] = [];
                    const list = this.colOptions[letter];
                    if (list.some((o) => o.label.toLowerCase() === label.toLowerCase())) {
                        this.optionError = `"${label}" is already in the list.`;
                        return;
                    }
                    if (list.length >= this.optionLimit) {
                        this.optionError = `Limit reached: ${this.optionLimit} options per column.`;
                        return;
                    }
                    const palette = this.optionPalette?.length ? this.optionPalette : ['#64748B'];
                    list.push({ label, color: palette[list.length % palette.length] });
                    this.optionDraft = '';
                    this.optionError = '';
                },

                removeOption(letter, idx) {
                    const list = this.colOptions[letter];
                    if (!Array.isArray(list)) return;
                    list.splice(idx, 1);
                },

                /** Commit a rename on change; blank/duplicate revert the input. */
                renameOption(letter, idx, event) {
                    const list = this.colOptions[letter];
                    if (!Array.isArray(list)) return;
                    const label = (event.target.value || '').trim();
                    if (!label) {
                        event.target.value = list[idx].label;
                        this.optionError = 'Option labels cannot be blank.';
                        return;
                    }
                    const duplicate = list.some((o, i) => i !== idx && o.label.toLowerCase() === label.toLowerCase());
                    if (duplicate) {
                        event.target.value = list[idx].label;
                        this.optionError = `"${label}" is already in the list.`;
                        return;
                    }
                    event.target.value = label;
                    list[idx] = { ...list[idx], label };
                    this.optionError = '';
                },

                setOptionColor(letter, idx, color) {
                    const list = this.colOptions[letter];
                    if (!Array.isArray(list)) return;
                    list[idx] = { ...list[idx], color };
                },

                /**
                 * Editor header: explains what currently applies — existing
                 * field schema (managed), the untouched defaults, an explicit
                 * empty list, or the custom list.
                 */
                optionHint(letter) {
                    if (!this.isDynamic) {
                        const target = this.columnTarget(letter);
                        if (target && target !== '__new__') {
                            return 'This column feeds an existing field — its fixed schema applies. Options can only be set for a new column.';
                        }
                    }
                    const list = this.colOptions[letter];
                    if (list === undefined) {
                        const n = this.fileValues(letter).length;
                        return n
                            ? `No custom options yet — defaults apply. The file has ${n} distinct value${n === 1 ? '' : 's'} to start from below.`
                            : 'No custom options yet — the default options apply until you customize.';
                    }
                    if (list.length === 0) {
                        return 'Empty list — values found in the file are appended automatically during import.';
                    }
                    return `${list.length} option${list.length === 1 ? '' : 's'} — these replace the defaults.`;
                },

                typeLabel(key) {
                    return (columnTypes || {})[key] || key;
                },

                /**
                 * Note under the type list: managed columns feeding an existing
                 * field keep that field's fixed schema — the chosen type only
                 * takes effect if the column is imported as a new custom column.
                 */
                typeMenuNote() {
                    if (this.isDynamic || !this.typeMenu) return '';
                    const target = this.columnTarget(this.typeMenu.letter);
                    if (!target || target === '__new__') return '';
                    return 'This column feeds an existing field — its fixed schema applies. The chosen type takes effect only if the column is imported as a new custom column.';
                },

                /** Skipped (managed) or unchecked (dynamic) columns stay visible, just dimmed. */
                isDimmed(col) {
                    if (this.isDynamic) {
                        return col.letter !== this.titleColumn && !(this.importCols[col.letter] && this.importCols[col.letter].include);
                    }
                    return this.columnTarget(col.letter) === '';
                },

                /** Field key fed by this letter, '__new__' for a draft new column, or ''. */
                columnTarget(letter) {
                    const found = Object.entries(this.mapping || {}).find(([, mapped]) => mapped === letter);
                    if (found) {
                        return found[0];
                    }
                    return this.newCols[letter] ? '__new__' : '';
                },

                /** Dropdown change: one source column feeds exactly one target. */
                onColumnTarget(letter, value, header) {
                    Object.keys(this.mapping).forEach((key) => {
                        if (this.mapping[key] === letter) {
                            this.mapping[key] = '';
                            delete this.mappingSource[key];
                        }
                    });
                    delete this.newCols[letter];

                    if (value === '__new__') {
                        this.newCols[letter] = { name: (header || '').trim() };
                        return;
                    }

                    if (value) {
                        this.mapping[value] = letter;
                        this.mappingSource[value] = 'manual';
                        // The type button follows the field it now feeds.
                        const field = (targets?.fields || []).find((entry) => entry.key === value);
                        if (field && field.kind) {
                            this.colTypes[letter] = field.kind;
                        }
                    }

                    // Keep the step-2 badge honest: it mirrors the title mapping.
                    if (!this.isDynamic && (this.mapping[this.titleFieldKey()] || '') !== this.titleColumn) {
                        this.titleColumn = this.mapping[this.titleFieldKey()] || '';
                    }
                },

                /** Draft new columns to send with the import ({letter, name, type[, options]}). */
                newColumnsPayload() {
                    return Object.entries(this.newCols || {})
                        .filter(([, draft]) => (draft.name || '').trim() !== '')
                        .map(([letter, draft]) => {
                            const entry = {
                                letter,
                                name: draft.name.trim(),
                                type: this.colTypes[letter] || 'text',
                            };
                            // Custom options ride only for status/dropdown, and
                            // only once the editor actually customized them.
                            if (this.isOptionType(letter) && Array.isArray(this.colOptions[letter])) {
                                entry.options = this.colOptions[letter];
                            }
                            return entry;
                        });
                },

                /** First 10 data records as they will land. */
                previewRows() {
                    return (this.preview?.topRows || [])
                        .filter((row) => row.rowNumber >= (this.dataStart || 1))
                        .slice(0, 10);
                },

                /**
                 * Included file columns for a replacing dynamic import, in file
                 * order. The title column is always included — step 2 owns it.
                 */
                importColumnsPayload() {
                    return (this.preview?.columns || [])
                        .map((col) => col.letter)
                        .filter((letter) => letter === this.titleColumn || (this.importCols[letter] && this.importCols[letter].include))
                        .map((letter) => {
                            const entry = {
                                letter,
                                name: (this.importCols[letter]?.name || '').trim(),
                                type: this.colTypes[letter] || 'text',
                            };
                            // Same rule as the managed payload: only edited
                            // status/dropdown columns carry their option list.
                            if (this.isOptionType(letter) && Array.isArray(this.colOptions[letter])) {
                                entry.options = this.colOptions[letter];
                            }
                            return entry;
                        })
                        .filter((entry) => entry.name !== '');
                },

                failedRowsUrl() {
                    return (this.failedRowsUrlTemplate || '').replace('__BATCH__', this.result?.batchId ?? '');
                },

                async execute() {
                    this.globalError = '';
                    this.ensureTitleColumn();
                    this.executing = true;
                    try {
                        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const resp = await fetch(executeUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({
                                uploadId: this.uploadId,
                                originalName: this.originalName,
                                sheet: this.sheet,
                                headerRow: this.headerRow,
                                dataStart: this.dataStart,
                                mapping: this.mapping,
                                newColumns: this.isDynamic ? [] : this.newColumnsPayload(),
                                titleLetter: this.titleColumn || '',
                                columns: this.importColumnsPayload(),
                                columnSignature: Object.fromEntries(
                                    (this.preview?.columns || []).map((col) => [col.letter, col.label || '']),
                                ),
                            }),
                        });
                        const data = await this.readJson(resp);
                        if (!resp.ok) throw new Error(data.message || 'Import failed.');
                        this.result = data;
                        this.step = 3;
                    } catch (e) {
                        this.globalError = e.message || 'The import failed.';
                    } finally {
                        this.executing = false;
                    }
                },

                /**
                 * Default unmatched file columns to "＋ New column…" (name from
                 * the header) so no column is silently skipped. Runs once per
                 * file: later revisits of step 3 keep the user's choices.
                 */
                initNewCols() {
                    if (this.newColsSeeded) return;
                    const used = new Set(Object.values(this.mapping || {}).filter((letter) => letter !== ''));
                    this.newCols = {};
                    (this.preview?.columns || []).forEach((col) => {
                        const header = (col.label || '').trim();
                        if (header === '' || used.has(col.letter)) return;
                        this.newCols[col.letter] = { name: header };
                    });
                    this.newColsSeeded = true;
                },

                reset() {
                    this.globalError = '';
                    this.result = null;
                    this.analysis = null;
                    this.preview = null;
                    this.sheet = '';
                    this.headerRow = 1;
                    this.dataStart = 2;
                    this.mapping = {};
                    this.mappingSource = {};
                    this.titleColumn = '';
                    this.importCols = {};
                    this.newCols = {};
                    this.newColsSeeded = false;
                    this.colTypes = {};
                    this.colTypesSeeded = false;
                    this.seededSignature = null;
                    this.typeMenu = null;
                    this.colOptions = {};
                    this.optionsMenu = null;
                    this.optionDraft = '';
                    this.optionError = '';
                    (targets?.fields || []).forEach(f => { this.mapping[f.key] = ''; });
                    this.step = 1;
                    this.phase = 'header';
                    this.uploadId = null;
                    this.originalName = null;
                    this.progress = 0;
                }
            }));
        });
    </script>
</x-layouts.dashboard>
