<x-layouts.dashboard title="Import {{ $title }}">
    <x-admin.page-header eyebrow="Import" title="Import {{ $title }}" description="Classic import — no Livewire. If the modal or the lean Livewire tab still 500s, use this tab. When the import finishes, return to the original tab.">
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
             uploadUrl: @js(route('import.upload-stream')),
             uploadChunkUrl: @js(route('import.upload-chunk')),
             targets: @js($targets)
         })"
         x-init="init()">

        <div class="admin-surface overflow-hidden">
            <div class="flex items-center gap-2 border-b border-base-300 px-6 py-4">
                <template x-for="(label, idx) in ['Source file','Map columns','Import']" :key="idx">
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
                {{-- Global error / success --}}
                <div x-show="globalError" x-cloak class="rounded-md border border-error/30 bg-error/10 p-3 text-sm text-error" x-text="globalError"></div>
                <div x-show="result" x-cloak class="rounded-md border p-4 text-sm" :class="(result?.failed > 0 || result?.status === 'failed') ? 'border-error/30 bg-error/10 text-error' : 'border-success/30 bg-success/10 text-success'">
                    <p class="font-semibold" x-text="result ? `Import ${result.status.replaceAll('_',' ')}.` : ''"></p>
                    <p class="mt-0.5 text-xs opacity-80" x-text="result ? `${Number(result.processed).toLocaleString()} processed, ${Number(result.failed).toLocaleString()} failed · batch #${result.batchId}` : ''"></p>
                    <div class="mt-3 flex gap-2">
                        <a :href="backUrl" class="admin-primary-button">Back to table</a>
                        <button type="button" @click="reset()" class="admin-secondary-button">Import another file</button>
                    </div>
                </div>

                {{-- Upload --}}
                <div x-show="!result">
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Excel / CSV file</label>
                    <input type="file" @change="onFile($el.files[0])" :disabled="uploading || analyzing" class="admin-control w-full" accept=".xlsx,.xls,.csv,.txt">
                    <span class="mt-1 block text-xs font-semibold text-primary" x-show="uploading" x-cloak>Uploading... <span x-text="progress"></span>%</span>
                    <span class="mt-1 block text-xs font-semibold text-primary" x-show="analyzing" x-cloak>Analyzing workbook...</span>
                     <span class="mt-1 block text-xs text-base-content/50">The file streams in 1 MB chunks via <code class="font-mono">POST /import/upload-chunk</code> (multipart, firewall-safe; falls back to <code class="font-mono">PUT /import/upload-stream</code>) — then analysis runs in a plain <code class="font-mono">POST</code> (not <code class="font-mono">/livewire/update</code>).</span>
                </div>

                {{-- Preview --}}
                <div x-show="preview && !result" x-cloak class="space-y-4">
                    <div x-show="analysis && analysis.sheets && analysis.sheets.length > 1" class="max-w-xs">
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Sheet</label>
                        <select x-model="sheet" @change="reAnalyze()" class="admin-control w-full">
                            <template x-for="s in (analysis?.sheets || [])" :key="s.name">
                                <option :value="s.name" x-text="s.name"></option>
                            </template>
                        </select>
                    </div>

                    <div class="flex flex-wrap items-end gap-4">
                        <div>
                            <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Header row</label>
                            <input type="number" min="1" x-model.number="headerRow" @change="reAnalyze()" class="admin-control w-24 tabular-nums">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">First data row</label>
                            <input type="number" :min="headerRow+1" x-model.number="dataStart" @change="reAnalyze()" class="admin-control w-24 tabular-nums">
                        </div>
                        <div class="ml-auto flex flex-wrap gap-2 text-[11px] font-bold uppercase tracking-[0.08em]">
                            <span class="rounded-full bg-primary/10 px-3 py-1 text-primary tabular-nums" x-text="`${Number(preview?.totalRows || 0).toLocaleString()} data rows`"></span>
                            <span class="rounded-full bg-base-200 px-3 py-1 text-base-content/60 tabular-nums" x-text="`${preview?.totalColumns || 0} columns`"></span>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-md border border-base-300">
                        <table class="min-w-full border-collapse text-left text-xs">
                            <thead>
                                <tr class="border-b border-base-300 bg-base-200/60">
                                    <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-[0.08em] text-base-content/45">Row</th>
                                    <template x-for="col in (preview?.columns || []).slice(0,12)" :key="col.letter">
                                        <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-[0.08em] text-base-content/45" x-text="col.letter"></th>
                                    </template>
                                </tr>
                                <tr class="border-b border-base-300">
                                    <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-[0.08em] text-primary">Header</th>
                                    <template x-for="col in (preview?.columns || []).slice(0,12)" :key="col.letter">
                                        <th class="max-w-[150px] truncate px-3 py-2 font-semibold text-base-content" :title="col.label || '(untitled)'" x-text="col.label || '(untitled)'"></th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-base-300">
                                <template x-for="row in (preview?.sampleRows || [])" :key="row.rowNumber">
                                    <tr class="hover:bg-base-200/50">
                                        <td class="px-3 py-1.5 tabular-nums text-base-content/40" x-text="row.rowNumber"></td>
                                        <template x-for="col in (preview?.columns || []).slice(0,12)" :key="col.letter">
                                            <td class="max-w-[150px] truncate px-3 py-1.5 text-base-content/80" :title="row.cells[col.letter] || ''" x-text="row.cells[col.letter] || ''"></td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-base-content/50" x-text="`Showing the first ${Math.min(12, preview?.totalColumns || 0)} of ${preview?.totalColumns || 0} columns and up to 4 sample rows.`"></p>
                </div>

                {{-- Mapping --}}
                <div x-show="preview && !result" x-cloak class="rounded-md border border-base-300">
                    <div class="flex items-center justify-between border-b border-base-300 bg-base-200/40 px-4 py-3">
                        <p class="text-xs font-bold uppercase tracking-[0.08em] text-base-content/60" x-text="`Map columns — ${targets?.label || tableKey} — ${sheet || 'the file'}`"></p>
                        <span class="rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.08em] text-primary tabular-nums" x-text="`${mappedCount()} / ${(targets?.fields || []).length} fields mapped`"></span>
                    </div>
                    <template x-for="label in missingRequired()" :key="label">
                        <span class="m-2 inline-flex rounded-full bg-error/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.08em] text-error" x-text="`Required: ${label}`"></span>
                    </template>
                    <div class="max-h-[46vh] divide-y divide-base-300 overflow-y-auto">
                        <template x-for="field in (targets?.fields || [])" :key="field.key">
                            <div class="grid grid-cols-1 items-center gap-2 px-4 py-2.5 sm:grid-cols-[minmax(0,200px)_minmax(0,1fr)_minmax(0,200px)] sm:gap-3" :class="field.required && !mapping[field.key] ? 'bg-error/5' : ''">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-base-content"><span x-text="field.label"></span><span x-show="field.required" class="ml-0.5 text-error">*</span></p>
                                    <p class="text-[11px] uppercase tracking-[0.06em] text-base-content/45" x-text="field.kind"></p>
                                </div>
                                <select x-model="mapping[field.key]" class="admin-control w-full font-mono text-xs">
                                    <option value="">-- not mapped --</option>
                                    <template x-for="col in (preview?.columns || [])" :key="col.letter">
                                        <option :value="col.letter" x-text="`${col.letter} · ${col.label || '(untitled)'}`"></option>
                                    </template>
                                </select>
                                <p class="hidden truncate text-xs text-base-content/50 sm:block" :title="sampleFor(field.key)" x-text="sampleFor(field.key) || '--'"></p>
                            </div>
                        </template>
                    </div>
                    <div class="flex items-center justify-between border-t border-base-300 px-4 py-3">
                        <span class="text-xs text-base-content/50">Only mapped columns are imported; existing records are updated by stable identifiers.</span>
                        <button type="button" @click="execute()" :disabled="executing" class="admin-primary-button">
                            <span x-show="!executing" x-text="`Start import (${Number(preview?.totalRows || 0).toLocaleString()} rows)`"></span>
                            <span x-show="executing">Importing...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <p class="mt-4 text-center text-xs text-base-content/40">
            Classic import never calls <code class="font-mono">/livewire/update</code>. If this tab also fails, share the red banner text — it now comes from a plain JSON <code class="font-mono">POST</code> and <code class="font-mono">storage/logs/laravel.log</code> <code class="font-mono">classicImport.*</code> breadcrumbs.
        </p>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('classicImporter', ({ tableKey, analyzeUrl, executeUrl, uploadUrl, uploadChunkUrl, targets }) => ({
                tableKey, analyzeUrl, executeUrl, uploadUrl, targets,
                backUrl: @js($backUrl),
                step: 1,
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
                result: null,

                init() {
                    // blank mapping
                    const fields = targets?.fields || [];
                    fields.forEach(f => this.mapping[f.key] = '');
                },

                async onFile(file) {
                    this.globalError = '';
                    this.result = null;
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
                        // Primary: POST multipart chunk (1 MB, stays under cPanel's 2M post_max_size and avoids ModSecurity PUT blocking).
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
                                // If POST endpoint not deployed (404) or returns 405, fall back to PUT for remaining chunks.
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
                                try { const p = JSON.parse(detail); if (p?.message) detail = p.message; } catch { if (detail.trimStart().startsWith('<')) detail = `Upload blocked by web firewall (HTTP ${resp.status}). Tried ${usePost ? 'POST' : 'PUT'} ${usePost ? uploadChunkUrl : uploadUrl} — ask host to allow it or share this HTML snippet.`; }
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
                        const data = await resp.json();
                        if (!resp.ok) throw new Error(data.message || 'Analysis failed.');
                        this.analysis = data.analysis;
                        this.preview = data.preview;
                        this.sheet = this.preview.sheet || this.sheet;
                        this.headerRow = this.preview.headerRow || 1;
                        this.dataStart = this.preview.dataStart || this.headerRow + 1;
                        // seed blank mapping if empty
                        if (Object.keys(this.mapping).length === 0) {
                            (targets?.fields || []).forEach(f => this.mapping[f.key] = '');
                        }
                    } catch (e) {
                        this.globalError = e.message || 'The workbook could not be analyzed.';
                        throw e;
                    } finally {
                        this.analyzing = false;
                    }
                },

                async reAnalyze() {
                    if (!this.preview) return;
                    try { await this.analyze(); } catch {}
                },

                mappedCount() {
                    return Object.values(this.mapping || {}).filter(v => v !== '').length;
                },

                missingRequired() {
                    const fields = targets?.fields || [];
                    return fields.filter(f => f.required && !this.mapping[f.key]).map(f => f.label);
                },

                sampleFor(key) {
                    const col = (this.preview?.columns || []).find(c => c.letter === this.mapping[key]);
                    const samples = (col?.samples || []).filter(Boolean).slice(0,2);
                    return samples.length ? 'e.g. ' + samples.join(' | ') : '';
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
                            }),
                        });
                        const data = await resp.json();
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
                    (targets?.fields || []).forEach(f => this.mapping[f.key] = '');
                    this.step = 1;
                    this.uploadId = null;
                    this.originalName = null;
                    this.progress = 0;
                }
            }));
        });
    </script>
</x-layouts.dashboard>
