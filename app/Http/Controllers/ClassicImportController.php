<?php

namespace App\Http\Controllers;

use App\Models\DynamicTable;
use App\Services\ImportMappingService;
use App\Services\SourceWorkbookImportService;
use App\Support\ImportFatalCapture;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ClassicImportController extends Controller
{
    /**
     * Show the classic (non-Livewire) import page. This page never calls
     * /livewire/update, so the 500 seen on srf.mcbtsi.com for 5 MB files
     * (Livewire snapshot / middleware) cannot happen here. The file still
     * streams via PUT /import/upload-stream, then analysis runs in a plain
     * POST that returns JSON.
     */
    public function show(Request $request, string $table)
    {
        abort_unless($request->user()?->canImport(), 403);

        $title = ImportMappingService::TARGETS[$table]['label'] ?? null;
        $isDynamic = DynamicTable::query()->where('key', $table)->exists();

        if ($title === null && ! $isDynamic) {
            abort(404, 'Import is not supported for this table.');
        }

        $title = $title ?? Str::headline(str_replace('-', ' ', $table));

        $backUrl = match ($table) {
            'installed-products' => route('installed-products'),
            'service-requests' => route('service-requests'),
            'technical-reports' => route('technical-reports'),
            'history-reports' => route('history-reports'),
            'personnel' => route('personnel'),
            default => $isDynamic ? route('tables.show', $table) : route('tables'),
        };

        return view('import.classic', [
            'tableKey' => $table,
            'title' => $title,
            'backUrl' => $backUrl,
            'targets' => ImportMappingService::TARGETS[$table] ?? null,
        ]);
    }

    /**
     * Analyze the assembled streamed file and return JSON preview.
     * Called via fetch POST from the classic page's Alpine, not via Livewire.
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
            // If a specific sheet/row was requested (user changed dropdown), preview that sheet.
            if ($sheet !== null && $sheet !== '') {
                $preview = $mappingService->previewSheet($absolute, $sheet, $headerRow, $dataStart);
                // Also return sheet list for the picker.
                $analysis = $mappingService->analyze($absolute, $table);
                $analysis['preview'] = $preview;

                return response()->json([
                    'analysis' => $analysis,
                    'preview' => $preview,
                ]);
            }

            $analysis = $mappingService->analyze($absolute, $table);

            return response()->json([
                'analysis' => $analysis,
                'preview' => $analysis['preview'],
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::error('classicImport.analyze.failed', ['upload_id' => $uploadId, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'This file could not be read as an Excel or CSV workbook ('.Str::limit($e->getMessage(), 180).'). Re-export it as .xlsx or .csv and try again.'], 422);
        }
    }

    /**
     * Execute the import with the user's column mapping.
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

        $uploadId = $request->input('uploadId');
        $originalName = urldecode((string) $request->input('originalName'));
        $sheet = (string) $request->input('sheet');
        $headerRow = (int) $request->input('headerRow');
        $dataStart = (int) $request->input('dataStart');
        $mapping = $request->input('mapping', []);

        $targets = ImportMappingService::TARGETS[$table] ?? null;

        if ($targets === null) {
            return response()->json(['message' => 'This table does not support mapped imports yet.'], 422);
        }

        $mapping = collect($mapping)->map(fn ($v) => strtoupper(trim((string) $v)))->all();

        $missing = collect($targets['fields'])->filter(fn ($f) => $f['required'] && ($mapping[$f['key']] ?? '') === '')->pluck('label');
        if ($missing->isNotEmpty()) {
            return response()->json(['message' => 'Map the required field(s) first: '.$missing->implode(', ').'.'], 422);
        }

        $used = collect($mapping)->filter(fn ($v) => $v !== '');
        if ($used->isEmpty()) {
            return response()->json(['message' => 'Map at least one column before importing.'], 422);
        }
        $duplicates = $used->duplicates();
        if ($duplicates->isNotEmpty()) {
            return response()->json(['message' => 'Multiple fields map to column '.$duplicates->first().'. Each source column can be used once.'], 422);
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $relative = ImportStreamController::storedPathFor($uploadId, $extension);
        $absolute = Storage::disk(config('filesystems.default'))->path($relative);

        if (! is_file($absolute)) {
            return response()->json(['message' => 'The uploaded file is no longer on the server. Re-upload it.'], 422);
        }

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

        app(ImportMappingService::class)->rememberMapping($table, $mapping);

        return response()->json([
            'status' => $batch->status,
            'processed' => $batch->processed_rows,
            'failed' => $batch->failed_rows,
            'batchId' => $batch->id,
        ]);
    }
}
