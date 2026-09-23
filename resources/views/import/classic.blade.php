<x-layouts.dashboard title="Import {{ $title }}">
    <x-admin.page-header eyebrow="Import" title="Import {{ $title }}" description="Upload, preview and map your workbook, then import. Existing records are updated by stable identifiers; nothing is written until you press Start import.">
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
             columnTypeHelp: @js($columnTypeHelp ?? [])
         })"
         x-init="init()">

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

                {{-- Upload --}}
                <div x-show="!result">
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Excel / CSV file</label>
                    <input type="file" @change="onFile($el.files[0])" :disabled="uploading || analyzing" class="admin-control w-full" accept=".xlsx,.xls,.csv,.txt">
                    <span class="mt-1 block text-xs font-semibold text-primary" x-show="uploading" x-cloak>Uploading... <span x-text="progress"></span>%</span>
                    <span class="mt-1 block text-xs font-semibold text-primary" x-show="analyzing" x-cloak>Analyzing workbook...</span>
                    <span class="mt-1 block text-xs text-base-content/50">
                        The file streams in 1 MB chunks (<span class="font-mono">POST /import/upload-chunk</span>, firewall-safe with a
                        <span class="font-mono">PUT</span> fallback) — large workbooks are fine, and column mapping is auto-suggested from the headers.
                    </span>
                </div>

                {{-- Preview: "What is your first row?" picker, then mapping --}}
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

                {{-- Title column: "What is your first column?" --}}
                <div x-show="preview && !result && phase === 'title'" x-cloak class="space-y-4">
                    <div>
                        <h3 class="text-lg font-bold text-base-content">What is your first column?</h3>
                        <p class="mt-0.5 text-xs text-base-content/55" x-text="titleHint()"></p>
                    </div>

                    <div class="overflow-x-auto rounded-md border border-base-300">
                        <table class="min-w-full border-collapse text-left text-xs">
                            <thead>
                                <tr class="border-b border-base-300 bg-base-200/60">
                                    <th class="w-12 px-3 py-2"></th>
                                    <template x-for="col in (preview?.columns || []).slice(0,12)" :key="col.letter">
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
                                        <template x-for="col in (preview?.columns || []).slice(0,12)" :key="col.letter">
                                            <td class="max-w-[150px] truncate px-3 py-1.5" :class="titleColumn === col.letter ? 'bg-primary/15 font-semibold text-base-content' : 'text-base-content/70'" :title="row.cells[col.letter] || ''" x-text="row.cells[col.letter] || ''"></td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-base-300 pt-4">
                        <span class="text-xs text-base-content/50" x-text="titleColumn ? `Title column: ${titleColumn}` : 'No title column selected — rows fall back to a content digest.'"></span>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="phase = 'header'" class="admin-secondary-button">Back</button>
                            <button type="button" @click="phase = 'columns'; step = 3" class="admin-primary-button">Next</button>
                        </div>
                    </div>
                </div>

                {{-- Customize columns: the final step before import --}}
                <div x-show="preview && !result && phase === 'columns'" x-cloak class="space-y-4">
                    <div>
                        <h3 class="text-lg font-bold text-base-content">Customize your columns</h3>
                        <p class="mt-0.5 text-xs text-base-content/55" x-text="isDynamic ? 'Every checked column becomes a table column. Importing replaces the whole table.' : 'Choose what each file column feeds. Importing replaces every record.'"></p>
                    </div>

                    <div class="max-h-[46vh] divide-y divide-base-300 overflow-y-auto rounded-md border border-base-300">
                        <template x-for="col in (preview?.columns || [])" :key="col.letter">
                            <div class="space-y-2 px-4 py-3">
                                <template x-if="isDynamic">
                                    <div class="grid grid-cols-1 items-center gap-2 sm:grid-cols-[minmax(0,32px)_minmax(0,1fr)_minmax(0,180px)] sm:gap-3">
                                        <input type="checkbox" x-model="importCols[col.letter].include" class="checkbox checkbox-sm checkbox-primary" :aria-label="`Include column ${col.letter}`">
                                        <div class="min-w-0">
                                            <input type="text" x-model="importCols[col.letter].name" maxlength="100" class="admin-control w-full text-xs font-semibold" :aria-label="`Name for column ${col.letter}`" placeholder="Column name">
                                            <p class="truncate text-xs text-base-content/50" x-text="columnSample(col)"></p>
                                        </div>
                                        <div>
                                            <select x-model="importCols[col.letter].type" class="admin-control w-full font-mono text-xs" :aria-label="`Type for column ${col.letter}`">
                                                <template x-for="(label, key) in (columnTypes || {})" :key="key">
                                                    <option :value="key" x-text="label"></option>
                                                </template>
                                            </select>
                                            <p class="mt-1 text-[11px] text-base-content/50" x-text="(columnTypeHelp || {})[importCols[col.letter].type] || ''"></p>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="!isDynamic">
                                    <div class="grid grid-cols-1 items-center gap-2 sm:grid-cols-[minmax(0,180px)_minmax(0,1fr)] sm:gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-base-content"><span class="mr-1.5 font-mono text-xs text-base-content/45" x-text="col.letter"></span><span x-text="col.label || '(untitled)'"></span></p>
                                            <p class="truncate text-xs text-base-content/50" x-text="columnSample(col)"></p>
                                        </div>
                                        <select :value="columnTarget(col.letter)" @change="onColumnTarget(col.letter, $el.value)" class="admin-control w-full font-mono text-xs" :aria-label="`Target for column ${col.letter}`">
                                            <option value="">-- not imported --</option>
                                            <template x-for="field in (targets?.fields || [])" :key="field.key">
                                                <option :value="field.key" x-text="`${field.label} (${field.kind})`"></option>
                                            </template>
                                        </select>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 pt-4">
                        <div class="flex flex-wrap items-center gap-2 text-[11px] font-bold uppercase tracking-[0.08em]">
                            <template x-if="isDynamic">
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-primary tabular-nums" x-text="`${importColumnsPayload().length} columns`"></span>
                            </template>
                            <template x-if="!isDynamic">
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-primary tabular-nums" x-text="`${columnsMappedCount()} mapped`"></span>
                                <template x-for="label in columnsMissingRequired()" :key="label">
                                    <span class="rounded-full bg-error/10 px-3 py-1 text-error" x-text="`Required: ${label}`"></span>
                                </template>
                            </template>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="columnsBack()" class="admin-secondary-button">Back</button>
                            <button type="button" @click="execute()" :disabled="executing" class="admin-primary-button">
                                <span x-show="!executing" x-text="`Start import (${Number(preview?.totalRows || 0).toLocaleString()} rows)`"></span>
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
            Alpine.data('classicImporter', ({ tableKey, analyzeUrl, executeUrl, failedRowsUrlTemplate, uploadUrl, uploadChunkUrl, targets, isDynamic, columnTypes, columnTypeHelp }) => ({
                tableKey, analyzeUrl, executeUrl, failedRowsUrlTemplate, uploadUrl, uploadChunkUrl, targets, isDynamic, columnTypes, columnTypeHelp,
                backUrl: @js($backUrl),
                step: 1,
                phase: 'header',
                titleColumn: '',
                importCols: {},
                importCols: {},
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
                        this.step = 2;
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

                /** Next: analyze with the chosen rows, then the title column step. */
                async goMap() {
                    try {
                        await this.analyze();
                        this.titleColumn = this.mapping['__identity__'] || this.mapping['name'] || this.mapping[this.titleFieldKey()] || '';
                        this.initImportCols();
                        this.initImportCols();
                        this.phase = 'title';
                        this.step = 2;
                    } catch {}
                },

                /** Default include/name/type per file column for a replacing import. */
                initImportCols() {
                    const existing = {};
                    (targets?.fields || []).forEach((field) => {
                        existing[this.normalizeLabel(field.label)] = field.kind;
                    });
                    this.importCols = {};
                    (this.preview?.columns || []).forEach((col) => {
                        const header = (col.label || '').trim();
                        this.importCols[col.letter] = {
                            include: header !== '',
                            name: header,
                            type: existing[this.normalizeLabel(header)] || 'text',
                        };
                    });
                },

                normalizeLabel(label) {
                    return (label || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
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

                stepLabels() {
                    return ['Source file', 'First column', 'Customize columns'];
                },

                columnsBack() {
                    this.phase = 'title';
                    this.step = 2;
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

                /** Title column pick: identity for dynamic tables, the title
                    field for managed ones. Name falls back automatically when
                    unmapped, so one click never trips the one-column rule. */
                pickTitleColumn(letter) {
                    const key = this.titleFieldKey();
                    if (!key) {
                        return;
                    }
                    this.titleColumn = letter;
                    this.mapping[key] = letter;
                    this.mappingSource[key] = 'manual';
                },

                columnSample(col) {
                    const samples = (col?.samples || []).filter(Boolean).slice(0, 2);
                    return samples.length ? 'e.g. ' + samples.join(' | ') : '';
                },

                /** Field key currently fed by this letter, '__new__', or ''. */
                columnTarget(letter) {
                    const found = Object.entries(this.mapping || {}).find(([, mapped]) => mapped === letter);
                    return found ? found[0] : '';
                },

                onColumnTarget(letter, value) {
                    if (value) {
                        this.mapping[value] = letter;
                        this.mappingSource[value] = 'manual';
                    }
                },

                /** Included file columns for a replacing dynamic import, in file order. */
                importColumnsPayload() {
                    return (this.preview?.columns || [])
                        .map((col) => col.letter)
                        .filter((letter) => this.importCols[letter] && this.importCols[letter].include)
                        .map((letter) => ({
                            letter,
                            name: (this.importCols[letter].name || '').trim(),
                            type: this.importCols[letter].type || 'text',
                        }))
                        .filter((entry) => entry.name !== '');
                },

                failedRowsUrl() {
                    return (this.failedRowsUrlTemplate || '').replace('__BATCH__', this.result?.batchId ?? '');
                },

                async execute() {
                    this.globalError = '';
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
