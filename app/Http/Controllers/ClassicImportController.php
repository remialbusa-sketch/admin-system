<?php

namespace App\Http\Controllers;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\ImportBatch;
use App\Services\ColumnTypeRegistry;
use App\Services\DynamicTableImportService;
use App\Services\ImportMappingService;
use App\Services\ImportUndoService;
use App\Services\SourceWorkbookImportService;
use App\Support\ImportFatalCapture;
use App\Support\ImportTargetResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ClassicImportController extends Controller
{
    /**
     * Show the classic (non-Livewire) import page. This page never calls
     * /livewire/update, so Livewire-snapshot failures cannot happen here.
     * The file streams via POST /import/upload-chunk (PUT fallback), then
     * analysis and execution run as plain JSON POSTs.
     */
    public function show(Request $request, string $table)
    {
        abort_unless($request->user()?->canImport(), 403);

        $targets = ImportTargetResolver::for($table);

        if ($targets === null) {
            abort(404, 'Import is not supported for this table.');
        }

        return view('import.classic', [
            'tableKey' => $table,
            'title' => $targets['label'],
            'backUrl' => $this->backUrl($table),
            'targets' => $targets,
            'isDynamic' => (bool) $targets['dynamic'],
            'columnTypes' => $this->importableColumnTypes(),
            'columnTypeHelp' => [
                'text' => 'Free form information and annotations',
                'long_text' => 'Longer notes and descriptions',
                'number' => 'Numeric values for math and totals',
                'status' => 'Indicates the state or progress of each row',
                'dropdown' => 'Assign labels from a list to each row',
                'checkbox' => 'Checked or unchecked flag',
                'date' => 'Calendar dates',
                'email' => 'Email addresses',
                'phone' => 'Phone numbers',
                'link' => 'Web links',
                'location' => 'Places and addresses',
                'person' => 'People on each row',
                'files' => 'File attachments',
            ],
        ]);
    }

    /**
     * Types a file column may be created as during import (dynamic tables).
     * Formula is excluded — it computes instead of storing imported values.
     *
     * @return array<string, string> key => label
     */
    private function importableColumnTypes(): array
    {
        return collect(array_keys(app(ColumnTypeRegistry::class)->all()))
            ->reject(fn (string $key): bool => $key === 'formula')
            ->mapWithKeys(fn (string $key): array => [$key => Str::headline($key)])
            ->all();
    }

    /**
     * Analyze the assembled streamed file and return the preview plus the
     * auto-suggested and previously-committed mappings.
     */
    public function analyze(Request $request, string $table)
    {
        abort_unless($request->user()?->canImport(), 403);

        // OOM fatals are uncatchable — this turns the bare 500 into a JSON
        // message plus a marker file the admin can read.
        ImportFatalCapture::register('classic.analyze:'.$table, emitJson: true);

        $request->validate([
            'uploadId' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'originalName' => ['required', 'string', 'max:255'],
            'sheet' => ['nullable', 'string', 'max:255'],
            'headerRow' => ['nullable', 'integer', 'min:1', 'max:200'],
            'dataStart' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $targets = ImportTargetResolver::for($table);

        if ($targets === null) {
            return response()->json(['message' => 'This table does not support imports.'], 422);
        }

        $uploadId = $request->input('uploadId');
        $originalName = urldecode((string) $request->input('originalName'));
        $sheet = $request->input('sheet');
        $headerRow = $request->input('headerRow') !== null ? (int) $request->input('headerRow') : null;
        $dataStart = $request->input('dataStart') !== null ? (int) $request->input('dataStart') : null;

        Log::info('classicImport.analyze.enter', [
            'table' => $table,
            'upload_id' => $uploadId,
            'original' => $originalName,
            'sheet' => $sheet,
            'headerRow' => $headerRow,
            'dataStart' => $dataStart,
            'memory_limit' => ini_get('memory_limit'),
        ]);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, ['xlsx', 'xls', 'csv', 'txt'], true)) {
            return response()->json(['message' => 'That file type is not supported. Use .xlsx, .xls or .csv.'], 422);
        }

        $relative = ImportStreamController::storedPathFor($uploadId, $extension);
        $absolute = Storage::disk(config('filesystems.default'))->path($relative);

        Log::info('classicImport.analyze.storedPath', [
            'relative' => $relative,
            'absolute' => $absolute,
            'exists' => is_file($absolute),
            'bytes' => is_file($absolute) ? filesize($absolute) : null,
        ]);

        if (! is_file($absolute) || filesize($absolute) === 0) {
            return response()->json(['message' => 'The streamed upload did not reach the server. Try again — the chunks may have been blocked by the web firewall (ModSecurity) or the disk is full.'], 422);
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $mappingService = app(ImportMappingService::class);

        try {
            if ($sheet !== null && $sheet !== '') {
                // A specific sheet/row was requested (user changed the picker).
                $preview = $mappingService->previewSheet($absolute, $sheet, $headerRow, $dataStart);
                $analysis = $mappingService->analyze($absolute, $table);
                $analysis['preview'] = $preview;
            } else {
                $analysis = $mappingService->analyze($absolute, $table);
                $preview = $analysis['preview'];
            }

            return response()->json([
                'analysis' => $analysis,
                'preview' => $preview,
                'suggested' => $mappingService->suggestMapping($targets['fields'], $preview['columns'] ?? []),
                'recalled' => $mappingService->recallFor($targets['fields'], $table, $preview['columns'] ?? []),
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::error('classicImport.analyze.failed', ['upload_id' => $uploadId, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'This file could not be read as an Excel or CSV workbook ('.Str::limit($e->getMessage(), 180).'). Re-export it as .xlsx or .csv and try again.'], 422);
        }
    }

    /**
     * Starter settings for import-created columns — mirrors
     * ManagedTable::defaultColumnSettings so imported values validate.
     */
    private function defaultColumnSettings(string $type): array
    {
        return match ($type) {
            'status', 'dropdown' => [
                'multi' => $type === 'dropdown',
                'options' => [
                    ['index' => 0, 'label' => 'New', 'color' => '#64748B'],
                    ['index' => 1, 'label' => 'In Progress', 'color' => '#2563EB'],
                    ['index' => 2, 'label' => 'Done', 'color' => '#16A34A'],
                ],
            ],
            'number' => ['precision' => 2],
            default => [],
        };
    }

    /**
     * Execute the import. Dynamic tables are REPLACED wholesale (columns +
     * rows become the file); managed tables keep their fixed columns while
     * their rows are replaced. Nothing upserts onto old content anymore.
     */
    public function execute(Request $request, string $table)
    {
        abort_unless($request->user()?->canImport(), 403);

        // A full import can also OOM (formula ranges, big chunks) — same guard.
        ImportFatalCapture::register('classic.execute:'.$table, emitJson: true);

        $request->validate([
            'uploadId' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'originalName' => ['required', 'string', 'max:255'],
            'sheet' => ['required', 'string', 'max:255'],
            'headerRow' => ['required', 'integer', 'min:1', 'max:200'],
            'dataStart' => ['required', 'integer', 'min:1', 'max:500'],
            // Optional: dynamic replace flows send columns + titleLetter
            // instead of a field mapping (emptiness is checked below);
            // managed flows may send newColumns ("＋ New column…" drafts).
            'mapping' => ['array'],
            'newColumns' => ['sometimes', 'array'],
        ]);

        $targets = ImportTargetResolver::for($table);

        if ($targets === null) {
            return response()->json(['message' => 'This table does not support imports.'], 422);
        }

        $uploadId = $request->input('uploadId');
        $originalName = urldecode((string) $request->input('originalName'));
        $sheet = (string) $request->input('sheet');
        $headerRow = (int) $request->input('headerRow');
        $dataStart = (int) $request->input('dataStart');

        $mapping = collect($request->input('mapping', []))
            ->map(fn ($letter): string => strtoupper(trim((string) $letter)))
            ->all();

        if ($targets['dynamic']) {
            return $this->executeDynamicReplace($request, $table, $uploadId, $originalName, $sheet, $headerRow, $dataStart);
        }

        $missing = collect($targets['fields'])
            ->filter(fn (array $field): bool => $field['required'] && ($mapping[$field['key']] ?? '') === '')
            ->pluck('label');

        if ($missing->isNotEmpty()) {
            return response()->json(['message' => 'Map the required field(s) first: '.$missing->implode(', ').'.'], 422);
        }

        $used = collect($mapping)->filter(fn (string $letter): bool => $letter !== '');

        if ($used->isEmpty()) {
            return response()->json(['message' => 'Map at least one column before importing.'], 422);
        }

        $duplicates = $used->duplicates();

        if ($duplicates->isNotEmpty()) {
            return response()->json(['message' => 'Multiple fields map to column '.$duplicates->first().'. Each source column can be used once.'], 422);
        }

        $knownKeys = collect($targets['fields'])->pluck('key')->flip();
        $unknown = collect($mapping)->keys()->reject(fn (string $key): bool => $knownKeys->has($key));

        if ($unknown->isNotEmpty()) {
            return response()->json(['message' => 'Unknown import target field.'], 422);
        }

        // "＋ New column…" drafts (letter => name/type): validate structure
        // first - nothing may be written until every check below passes.
        $newColumns = collect($request->input('newColumns', []))->values();
        $allowedTypes = array_keys($this->importableColumnTypes());
        $seenNames = [];

        foreach ($newColumns as $index => $entry) {
            $position = $index + 1;
            $letter = strtoupper(trim((string) ($entry['letter'] ?? '')));
            $name = trim((string) ($entry['name'] ?? ''));
            $type = trim((string) ($entry['type'] ?? ''));

            if (! preg_match('/^[A-Z]{1,3}$/', $letter)) {
                return response()->json(['message' => "New column #{$position}: invalid source column."], 422);
            }

            if ($name === '' || mb_strlen($name) > 100) {
                return response()->json(['message' => "New column #{$position}: give it a name (max 100 characters)."], 422);
            }

            if (in_array($this->normalizeColumnName($name), $seenNames, true)) {
                return response()->json(['message' => "Column name '{$name}' is used twice."], 422);
            }

            if (! in_array($type, $allowedTypes, true)) {
                return response()->json(['message' => "Column '{$name}': unknown column type."], 422);
            }

            $seenNames[] = $this->normalizeColumnName($name);
            $newColumns[$index] = ['letter' => $letter, 'name' => $name, 'type' => $type];
        }

        // Each source column feeds one target only: reject letters already
        // connected to a field (and letters used by two drafts).
        $usedLetters = $used->values();

        foreach ($newColumns as $entry) {
            if ($usedLetters->contains($entry['letter'])) {
                return response()->json(['message' => 'Column '.$entry['letter'].' is already connected to a field. Each source column can be used once.'], 422);
            }

            $usedLetters->push($entry['letter']);
        }

        // Name-based match: a draft whose name equals an existing custom
        // column of this table overwrites it instead of duplicating it.
        $existingColumns = CustomTableColumn::query()
            ->where('table_key', $table)
            ->get()
            ->keyBy(fn (CustomTableColumn $column): string => $this->normalizeColumnName($column->name));

        $resolved = [];
        $toCreate = [];

        foreach ($newColumns as $entry) {
            $existing = $existingColumns->get($this->normalizeColumnName($entry['name']));

            if ($existing !== null) {
                $resolved[$existing->columnKey()] = $entry['letter'];
            } else {
                $toCreate[] = $entry;
            }
        }

        foreach ($resolved as $key => $letter) {
            if (($mapping[$key] ?? '') !== '' && $mapping[$key] !== $letter) {
                return response()->json(['message' => 'Column '.$letter.' and column '.$mapping[$key].' both connect to the same table field.'], 422);
            }
        }

        if ($toCreate !== [] && ! $request->user()?->canEditRecords()) {
            return response()->json(['message' => 'Your role cannot add columns to this table.'], 403);
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $relative = ImportStreamController::storedPathFor($uploadId, $extension);
        $absolute = Storage::disk(config('filesystems.default'))->path($relative);

        if (! is_file($absolute)) {
            return response()->json(['message' => 'The uploaded file is no longer on the server. Re-upload it.'], 422);
        }

        try {
            app(ImportUndoService::class)->purgeTable($table);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // Reuse first, create after the purge so a rejected import never
        // leaves structure behind.
        $position = (int) CustomTableColumn::query()->where('table_key', $table)->max('position');

        foreach ($toCreate as $entry) {
            $column = CustomTableColumn::create([
                'table_key' => $table,
                'name' => $entry['name'],
                'type' => $entry['type'],
                'settings' => $this->defaultColumnSettings($entry['type']),
                'position' => ++$position,
                'created_by' => $request->user()?->id,
            ]);

            $resolved[$column->columnKey()] = $entry['letter'];
        }

        $mapping = array_merge($mapping, $resolved);

        try {
            $batch = app(SourceWorkbookImportService::class)->importMapped(
                $absolute,
                $table,
                $sheet,
                $mapping,
                $headerRow,
                max($headerRow + 1, $dataStart),
                $request->user()?->id,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        app(ImportMappingService::class)->rememberMapping($table, $mapping, $this->columnSignature($request));

        return response()->json([
            'status' => $batch->status,
            'processed' => $batch->processed_rows,
            'failed' => $batch->failed_rows,
            'batchId' => $batch->id,
        ]);
    }

    /**
     * Replace a dynamic table wholesale: drop its columns, values and rows,
     * recreate the columns from the step-3 selection, then import every row
     * fresh. Structural, so editors and up only.
     */
    private function executeDynamicReplace(Request $request, string $table, string $uploadId, string $originalName, string $sheet, int $headerRow, int $dataStart)
    {
        abort_unless($request->user()?->canEditRecords(), 403);

        $entries = collect($request->input('columns', []))->values();
        $titleLetter = strtoupper(trim((string) $request->input('titleLetter', '')));

        if ($entries->isEmpty()) {
            return response()->json(['message' => 'Select at least one column to import.'], 422);
        }

        $allowedTypes = array_keys($this->importableColumnTypes());
        $seenNames = [];
        $validated = [];

        foreach ($entries as $index => $entry) {
            $position = $index + 1;
            $letter = strtoupper(trim((string) ($entry['letter'] ?? '')));
            $name = trim((string) ($entry['name'] ?? ''));
            $type = trim((string) ($entry['type'] ?? ''));

            if (! preg_match('/^[A-Z]{1,3}$/', $letter)) {
                return response()->json(['message' => "Column #{$position}: invalid source column."], 422);
            }

            if ($name === '' || mb_strlen($name) > 100) {
                return response()->json(['message' => "Column #{$position}: give it a name (max 100 characters)."], 422);
            }

            if (in_array(mb_strtolower($name), $seenNames, true)) {
                return response()->json(['message' => "Column name '{$name}' is used twice."], 422);
            }

            if (! in_array($type, $allowedTypes, true)) {
                return response()->json(['message' => "Column '{$name}': unknown column type."], 422);
            }

            $seenNames[] = mb_strtolower($name);
            $validated[] = ['letter' => $letter, 'name' => $name, 'type' => $type];
        }

        $letters = array_column($validated, 'letter');

        if (! in_array($titleLetter, $letters, true)) {
            return response()->json(['message' => 'Pick the title column from the imported columns.'], 422);
        }

        try {
            app(ImportUndoService::class)->assertSyncPaused($table);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $relative = ImportStreamController::storedPathFor($uploadId, $extension);
        $absolute = Storage::disk(config('filesystems.default'))->path($relative);

        if (! is_file($absolute)) {
            return response()->json(['message' => 'The uploaded file is no longer on the server. Re-upload it.'], 422);
        }

        $columnIds = CustomTableColumn::query()->where('table_key', $table)->pluck('id');

        CustomTableColumnValue::query()->whereIn('custom_column_id', $columnIds)->delete();
        DynamicRow::query()->where('table_key', $table)->forceDelete();
        CustomTableColumn::query()->where('table_key', $table)->forceDelete();

        $mapping = ['__identity__' => $titleLetter, 'name' => $titleLetter];

        foreach ($validated as $position => $entry) {
            $column = CustomTableColumn::create([
                'table_key' => $table,
                'name' => $entry['name'],
                'type' => $entry['type'],
                'settings' => $this->defaultColumnSettings($entry['type']),
                'position' => $position,
                'created_by' => $request->user()?->id,
            ]);

            $mapping[$column->columnKey()] = $entry['letter'];
        }

        try {
            $batch = app(DynamicTableImportService::class)->import(
                $absolute,
                $table,
                $sheet,
                $mapping,
                $headerRow,
                max($headerRow + 1, $dataStart),
                $request->user()?->id,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => $batch->status,
            'processed' => $batch->processed_rows,
            'failed' => $batch->failed_rows,
            'batchId' => $batch->id,
        ]);
    }

    /**
     * Header signature (letter => label) accompanying an execute call, so a
     * later recall only applies to same-layout workbooks. Capped: it is a
     * matching key, not data.
     *
     * @return array<string, string>
     */
    /**
     * Name-based matching key for custom columns: case- and punctuation-
     * insensitive, so "Cost-Center" and "cost center" are the same column.
     * (Mirrors ImportMappingService::normalizeLabel for auto-mapping.)
     */
    private function normalizeColumnName(string $name): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim($name))) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    private function columnSignature(Request $request): array
    {
        $signature = [];

        foreach (array_slice((array) $request->input('columnSignature', []), 0, 120, true) as $letter => $label) {
            $letter = strtoupper(trim((string) $letter));

            if (preg_match('/^[A-Z]{1,3}$/', $letter) && is_string($label)) {
                $signature[$letter] = mb_substr(trim($label), 0, 255);
            }
        }

        return $signature;
    }

    /**
     * Download the failed rows of a completed import as CSV, so the operator
     * can fix and re-import just those rows.
     */
    public function failedRows(Request $request, string $table, ImportBatch $batch)
    {
        abort_unless($request->user()?->canImport(), 403);

        if (! $this->batchBelongsTo($table, $batch)) {
            abort(404);
        }

        $failures = $batch->failures()->orderBy('row_number')->get();

        return response()->streamDownload(function () use ($failures): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, ['Row', 'Source ID', 'Error type', 'Error', 'Raw row']);

            foreach ($failures as $failure) {
                fputcsv($out, [
                    $failure->row_number,
                    $failure->source_record_id,
                    $failure->error_type,
                    $failure->error_message,
                    json_encode($failure->raw_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            fclose($out);
        }, 'import-'.$batch->id.'-failed-rows.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function batchBelongsTo(string $table, ImportBatch $batch): bool
    {
        $target = $batch->metadata['target_table'] ?? null;

        if ($target === null) {
            return false;
        }

        if ($target === $table) {
            return true; // dynamic table keys are stored as-is
        }

        $managedTable = match ($table) {
            'installed-products' => 'installations',
            'service-requests' => 'service_requests',
            'technical-reports' => 'technical_reports',
            'history-reports' => 'historical_tsms_reports',
            'personnel' => 'technical_personnel',
            default => null,
        };

        return $managedTable !== null && $target === $managedTable;
    }

    private function backUrl(string $table): string
    {
        return match ($table) {
            'installed-products' => route('installed-products'),
            'service-requests' => route('service-requests'),
            'technical-reports' => route('technical-reports'),
            'history-reports' => route('history-reports'),
            'personnel' => route('personnel'),
            default => DynamicTable::query()->where('key', $table)->exists()
                ? route('tables.show', $table)
                : route('tables'),
        };
    }
}
