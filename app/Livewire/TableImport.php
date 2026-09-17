<?php

namespace App\Livewire;

use App\Http\Controllers\ImportStreamController;
use App\Services\ImportMappingService;
use App\Services\SourceWorkbookImportService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Dedicated import wizard page (opened in a new tab) so the 5 MB
 * workbook analysis does NOT run inside the heavy ManagedTable grid
 * component. This component carries ONLY the wizard state, so its
 * Livewire snapshot is ~2 KB instead of the grid's ~50 KB + rows
 * pagination, avoiding the 500 on /livewire/update seen on
 * srf.mcbtsi.com for large files.
 *
 * Flow mirrors ManagedTable's wizard but lives at
 *   GET /tables/{table}/import  (auth + can:import)
 * and after a successful import the page shows a result + link back
 * to the original table tab.
 */
class TableImport extends Component
{
    public string $tableKey = '';

    public ?string $importStoredPath = null;

    public array $importAnalysis = [];

    public array $importPreview = [];

    public string $importSheet = '';

    public int $importHeaderRow = 1;

    public int $importDataStart = 2;

    /** target field key => source column letter ('' = unmapped). */
    public array $importMapping = [];

    public ?int $lastImportId = null;

    public array $importResult = [];

    public function mount(string $table): void
    {
        abort_unless(auth()->user()?->canImport(), 403);

        // Validate table key is a real import target (managed or dynamic).
        // Managed targets are the keys in ImportMappingService::TARGETS;
        // dynamic tables are created by the user and also importable via
        // the same stream → analyze path. For dynamic, we allow any key that
        // exists in the dynamic_tables table.
        $this->tableKey = $table;

        $targets = ImportMappingService::TARGETS[$this->tableKey] ?? null;
        $isDynamic = \App\Models\DynamicTable::query()->where('key', $this->tableKey)->exists();

        if ($targets === null && ! $isDynamic) {
            abort(404, 'Import is not supported for this table.');
        }
    }

    public function canImport(): bool
    {
        return (bool) auth()->user()?->canImport();
    }

    public function title(): string
    {
        $targets = ImportMappingService::TARGETS[$this->tableKey] ?? null;

        return $targets['label'] ?? Str::headline(str_replace('-', ' ', $this->tableKey));
    }

    /**
     * Step 1: streamed upload finished. Identical to ManagedTable's
     * analyzeStreamedImport — extracted so the lean component never
     * hydrates grid rows. Never becomes a bare 500.
     */
    public function analyzeStreamedImport(string $uploadId, string $originalName): void
    {
        try {
            abort_unless($this->canImport(), 403);

            $decodedName = urldecode($originalName);
            Log::info('analyzeStreamedImport.enter', [
                'upload_id' => $uploadId,
                'original' => $originalName,
                'decoded' => $decodedName,
                'table' => $this->tableKey,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
                'via' => 'TableImport',
            ]);

            $extension = strtolower(pathinfo($decodedName, PATHINFO_EXTENSION));

            if (! in_array($extension, ['xlsx', 'xls', 'csv', 'txt'], true)
                || preg_match('/^[a-f0-9]{32}$/', $uploadId) !== 1) {
                Log::warning('analyzeStreamedImport.rejected', ['upload_id' => $uploadId, 'extension' => $extension, 'via' => 'TableImport']);
                $this->addError('importFile', 'That file type is not supported. Use .xlsx, .xls or .csv.');

                return;
            }

            $this->reset(['importStoredPath', 'importAnalysis', 'importPreview', 'importSheet', 'importHeaderRow', 'importDataStart', 'importMapping', 'importResult', 'lastImportId']);

            $this->importStoredPath = ImportStreamController::storedPathFor($uploadId, $extension);
            $fullPath = $this->storedImportPath();

            Log::info('analyzeStreamedImport.storedPath', [
                'upload_id' => $uploadId,
                'relative' => $this->importStoredPath,
                'absolute' => $fullPath,
                'exists' => is_file($fullPath),
                'bytes' => is_file($fullPath) ? filesize($fullPath) : null,
                'via' => 'TableImport',
            ]);

            if (! is_file($fullPath) || filesize($fullPath) === 0) {
                Log::warning('analyzeStreamedImport.missingFile', ['upload_id' => $uploadId, 'path' => $fullPath, 'via' => 'TableImport']);
                $this->reset(['importStoredPath', 'importAnalysis', 'importPreview', 'importSheet', 'importHeaderRow', 'importDataStart', 'importMapping']);
                $this->addError('importFile', 'The streamed upload did not reach the server. Try again — the chunks may have been blocked by the web firewall (ModSecurity) or the disk is full.');

                return;
            }

            @set_time_limit(0);
            @ini_set('memory_limit', '512M');

            try {
                $mappingService = app(ImportMappingService::class);
                $this->importAnalysis = $mappingService->analyze($fullPath, $this->tableKey);
                $this->applyImportPreview($this->importAnalysis['preview']);
                $this->importMapping = $mappingService->blankMapping($this->tableKey);
                Log::info('analyzeStreamedImport.success', [
                    'upload_id' => $uploadId,
                    'sheet' => $this->importSheet,
                    'rows' => $this->importPreview['totalRows'] ?? null,
                    'cols' => $this->importPreview['totalColumns'] ?? null,
                    'via' => 'TableImport',
                ]);
            } catch (Throwable $exception) {
                report($exception);
                Log::error('analyzeStreamedImport.analyzeFailed', [
                    'upload_id' => $uploadId,
                    'error' => $exception->getMessage(),
                    'file' => $fullPath,
                    'via' => 'TableImport',
                ]);

                $this->reset(['importStoredPath', 'importAnalysis', 'importPreview', 'importSheet', 'importHeaderRow', 'importDataStart', 'importMapping']);
                $this->addError('importFile', 'This file could not be read as an Excel or CSV workbook ('.Str::limit($exception->getMessage(), 180).'). Re-export it as .xlsx or .csv and try again.');
            }
        } catch (Throwable $exception) {
            report($exception);
            Log::error('analyzeStreamedImport.outerFailed', [
                'upload_id' => $uploadId ?? null,
                'original' => $originalName ?? null,
                'error' => $exception->getMessage(),
                'via' => 'TableImport',
            ]);

            if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $exception;
            }

            $this->reset(['importStoredPath', 'importAnalysis', 'importPreview', 'importSheet', 'importHeaderRow', 'importDataStart', 'importMapping']);
            $this->addError('importFile', 'The workbook could not be analyzed ('.Str::limit($exception->getMessage(), 180).'). If it is a large file, the server memory limit (currently '.ini_get('memory_limit').') may be too low — set it to 512M in the hosting control panel.');
        }
    }

    public function updatedImportSheet(): void
    {
        abort_unless($this->canImport(), 403);

        if (! $this->importStoredPath || $this->importSheet === '') {
            return;
        }

        $this->refreshImportPreview(null, null);
    }

    public function updatedImportHeaderRow($value): void
    {
        abort_unless($this->canImport(), 403);

        if (! $this->importStoredPath || $this->importSheet === '') {
            return;
        }

        $this->refreshImportPreview((int) $value, null);
    }

    public function updatedImportDataStart($value): void
    {
        abort_unless($this->canImport(), 403);

        if (! $this->importStoredPath || $this->importSheet === '') {
            return;
        }

        $this->refreshImportPreview($this->importHeaderRow, (int) $value);
    }

    public function executeMappedImport(): void
    {
        abort_unless($this->canImport(), 403);

        if (! $this->importStoredPath) {
            $this->addError('importFile', 'Choose a workbook first.');

            return;
        }

        $targets = ImportMappingService::TARGETS[$this->tableKey] ?? null;

        if ($targets === null) {
            $this->addError('importMapping', 'This table does not support mapped imports yet.');

            return;
        }

        $mapping = collect($this->importMapping)
            ->map(fn ($letter): string => strtoupper(trim((string) $letter)));

        $missing = collect($targets['fields'])
            ->filter(fn (array $field): bool => $field['required'] && ($mapping[$field['key']] ?? '') === '')
            ->pluck('label');

        if ($missing->isNotEmpty()) {
            $this->addError('importMapping', 'Map the required field(s) first: '.$missing->implode(', ').'.');

            return;
        }

        $used = $mapping->filter(fn (string $letter): bool => $letter !== '');

        if ($used->isEmpty()) {
            $this->addError('importMapping', 'Map at least one column before importing.');

            return;
        }

        $duplicates = $used->duplicates();

        if ($duplicates->isNotEmpty()) {
            $this->addError('importMapping', 'Multiple fields map to column '.$duplicates->first().'. Each source column can be used once.');

            return;
        }

        $validLetters = collect($this->importPreview['columns'] ?? [])->pluck('letter')->flip();
        $invalid = $used->reject(fn (string $letter): bool => $validLetters->has($letter));

        if ($invalid->isNotEmpty()) {
            $this->addError('importMapping', 'Column '.$invalid->first().' does not exist on this sheet.');

            return;
        }

        try {
            $batch = app(SourceWorkbookImportService::class)->importMapped(
                $this->storedImportPath(),
                $this->tableKey,
                $this->importSheet,
                $mapping->all(),
                $this->importHeaderRow,
                max($this->importHeaderRow + 1, $this->importDataStart),
                auth()->id(),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->addError('importMapping', $exception->getMessage());

            return;
        }

        app(ImportMappingService::class)->rememberMapping($this->tableKey, $mapping->all());

        $this->lastImportId = $batch->id;
        $this->importResult = [
            'status' => $batch->status,
            'processed' => $batch->processed_rows,
            'failed' => $batch->failed_rows,
        ];
    }

    public function resetImportWizard(): void
    {
        $this->reset(['importStoredPath', 'importAnalysis', 'importPreview', 'importSheet', 'importHeaderRow', 'importDataStart', 'importMapping', 'importResult', 'lastImportId']);
    }

    private function refreshImportPreview(?int $headerRow, ?int $dataStart): void
    {
        try {
            $this->applyImportPreview(app(ImportMappingService::class)->previewSheet(
                $this->storedImportPath(),
                $this->importSheet,
                $headerRow,
                $dataStart,
            ));
        } catch (Throwable) {
            $this->addError('importSheet', 'That sheet could not be previewed.');
        }
    }

    private function applyImportPreview(array $preview): void
    {
        $this->importPreview = $preview;
        $this->importSheet = (string) ($preview['sheet'] ?? '');
        $this->importHeaderRow = (int) ($preview['headerRow'] ?? 1);
        $this->importDataStart = (int) ($preview['dataStart'] ?? 2);
    }

    private function storedImportPath(): string
    {
        return Storage::disk(config('filesystems.default'))->path((string) $this->importStoredPath);
    }

    public function render()
    {
        return view('livewire.table-import', [
            'tableKey' => $this->tableKey,
            'title' => $this->title(),
            'importPreview' => $this->importPreview,
            'importAnalysis' => $this->importAnalysis,
            'importTargets' => ImportMappingService::TARGETS[$this->tableKey] ?? null,
            'importMappedCount' => collect($this->importMapping)->filter(fn ($v) => $v !== '')->count(),
            'importMissingRequired' => collect(ImportMappingService::TARGETS[$this->tableKey]['fields'] ?? [])
                ->filter(fn (array $f) => $f['required'] && ($this->importMapping[$f['key']] ?? '') === '')
                ->pluck('label')->all(),
            'backUrl' => $this->backUrl(),
        ])->layout('layouts.dashboard')->title('Import '.$this->title());
    }

    private function backUrl(): string
    {
        // Lean tableKey → route mapping; dynamic tables fall through to tables.show.
        return match ($this->tableKey) {
            'installed-products' => route('installed-products'),
            'service-requests' => route('service-requests'),
            'technical-reports' => route('technical-reports'),
            'history-reports' => route('history-reports'),
            'personnel' => route('personnel'),
            default => \App\Models\DynamicTable::query()->where('key', $this->tableKey)->exists()
                ? route('tables.show', $this->tableKey)
                : route('tables'),
        };
    }
}
