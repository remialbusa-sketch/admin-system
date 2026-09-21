<?php

namespace App\Http\Controllers;

use App\Models\DynamicTable;
use App\Models\ImportBatch;
use App\Services\DynamicTableImportService;
use App\Services\ImportMappingService;
use App\Services\SourceWorkbookImportService;
use App\Support\ImportFatalCapture;
use App\Support\ImportTargetResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        ]);
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
     * Execute the import with the user's column mapping. Managed tables go
     * through SourceWorkbookImportService; dynamic (user-created) tables go
     * through DynamicTableImportService.
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
            'mapping' => ['required', 'array'],
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

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $relative = ImportStreamController::storedPathFor($uploadId, $extension);
        $absolute = Storage::disk(config('filesystems.default'))->path($relative);

        if (! is_file($absolute)) {
            return response()->json(['message' => 'The uploaded file is no longer on the server. Re-upload it.'], 422);
        }

        try {
            $batch = $targets['dynamic']
                ? app(DynamicTableImportService::class)->import(
                    $absolute,
                    $table,
                    $sheet,
                    $mapping,
                    $headerRow,
                    max($headerRow + 1, $dataStart),
                    $request->user()?->id,
                )
                : app(SourceWorkbookImportService::class)->importMapped(
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

        app(ImportMappingService::class)->rememberMapping($table, $mapping);

        return response()->json([
            'status' => $batch->status,
            'processed' => $batch->processed_rows,
            'failed' => $batch->failed_rows,
            'batchId' => $batch->id,
        ]);
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
