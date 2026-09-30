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
use App\Services\TableAggregationService;
use App\Support\ImportFatalCapture;
use App\Support\ImportOptionSeeder;
use App\Support\ImportTargetResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            // Step-3 option editor: the shared palette + cap the backend
            // enforces (ImportOptionSeeder), mirrored to the UI.
            'optionPalette' => ImportOptionSeeder::COLORS,
            'optionLimit' => ImportOptionSeeder::MAX_OPTIONS,
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
     * Step-3 custom options (from the option editor) replace the starter
     * list wholesale when present; null keeps the starters (back-compat
     * for callers that send no options).
     *
     * @param  array<int, array{label: string, color: string}>|null  $options
     */
    private function defaultColumnSettings(string $type, ?array $options = null): array
    {
        if (in_array($type, ['status', 'dropdown'], true) && $options !== null) {
            return [
                'multi' => $type === 'dropdown',
                'options' => collect($options)
                    ->map(fn (array $option, int $index): array => [
                        'index' => $index,
                        'label' => $option['label'],
                        'color' => $option['color'],
                    ])
                    ->values()
                    ->all(),
            ];
        }

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
     * Validate the step-3 option editor payload for one column.
     *
     * Returns null when the payload carries no options (the column keeps
     * the starter defaults), the normalized [{label, color}] list when
     * present (colors default to the shared palette), or a 422 response
     * when malformed. Shared by the managed and dynamic write paths so
     * both reject identically, before any file access or purge.
     *
     * @return array<int, array{label: string, color: string}>|JsonResponse|null
     */
    private function normalizeOptions(mixed $raw, string $type, string $columnName): array|JsonResponse|null
    {
        if ($raw === null) {
            return null;
        }

        if (! in_array($type, ['status', 'dropdown'], true)) {
            return response()->json(['message' => "Column '{$columnName}': only status and dropdown columns take options."], 422);
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            return response()->json(['message' => "Column '{$columnName}': options must be a list."], 422);
        }

        if (count($raw) > ImportOptionSeeder::MAX_OPTIONS) {
            return response()->json(['message' => "Column '{$columnName}': at most ".ImportOptionSeeder::MAX_OPTIONS.' options are allowed.'], 422);
        }

        $normalized = [];

        foreach ($raw as $option) {
            if (is_array($option)) {
                $label = trim((string) ($option['label'] ?? ''));
                $color = trim((string) ($option['color'] ?? ''));
            } else {
                // Tolerate legacy plain-string options like the seeder does.
                $label = is_string($option) ? trim($option) : '';
                $color = '';
            }

            if ($label === '') {
                return response()->json(['message' => "Column '{$columnName}': option labels cannot be blank."], 422);
            }

            if (mb_strlen($label) > 100) {
                return response()->json(['message' => "Column '{$columnName}': option labels are limited to 100 characters."], 422);
            }

            if ($color !== '' && ! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                return response()->json(['message' => "Column '{$columnName}': option colors must be hex like #2563EB."], 422);
            }

            $normalized[] = [
                'label' => $label,
                'color' => $color !== ''
                    ? strtoupper($color)
                    : ImportOptionSeeder::COLORS[count($normalized) % count(ImportOptionSeeder::COLORS)],
            ];
        }

        return $normalized;
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
            // Step-3 type picks for columns connected to an existing custom
            // field (letter => type). New columns carry their type inline.
            'columnTypes' => ['sometimes', 'array'],
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

        $allowedTypes = array_keys($this->importableColumnTypes());
        $notices = [];
        $payload = $this->manualManagedPayload($request, $targets, $allowedTypes, $notices);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        [$mapping, $newColumns, $typePicks] = $payload;

        $structure = $this->resolveManagedStructure($table, $mapping, $newColumns, $typePicks, $request->user(), $notices);

        if ($structure instanceof JsonResponse) {
            return $structure;
        }

        $resolved = $this->applyManagedStructure($table, $structure['stale'], $structure['toConvert'], $structure['toCreate'], $request->user());

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $mapping = array_merge($mapping, $resolved, $structure['nameMatched']);

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

        app(ImportMappingService::class)->rememberMapping($table, $mapping, $this->columnSignature($request));

        return response()->json([
            'status' => $batch->status,
            'processed' => $batch->processed_rows,
            'failed' => $batch->failed_rows,
            'batchId' => $batch->id,
            'notices' => $notices,
        ]);
    }

    /**
     * Manual step-3 payload shared by the single-shot execute() and the
     * resumable prepare() below: mapping checks, "＋ New column…" drafts,
     * connected-column type picks, and the one-letter-one-target rule.
     *
     * @return array{0: array<string, string>, 1: array<int, array{letter: string, name: string, type: string, options: array|null}>, 2: array<string, array{type: string, options: array|null}>}|JsonResponse
     */
    private function manualManagedPayload(Request $request, array $targets, array $allowedTypes, array &$notices): array|JsonResponse
    {
        $mapping = collect($request->input('mapping', []))
            ->map(fn ($letter): string => strtoupper(trim((string) $letter)))
            ->all();

        if ($failed = $this->checkManagedMapping($mapping, $targets)) {
            return $failed;
        }

        $seenNames = [];
        $newColumns = [];

        foreach (collect($request->input('newColumns', []))->values() as $index => $entry) {
            $normalized = $this->normalizeNewColumnEntry(is_array($entry) ? $entry : [], $index, $allowedTypes, $seenNames, $notices);

            if ($normalized instanceof JsonResponse) {
                return $normalized;
            }

            $newColumns[] = $normalized;
        }

        $typePicks = $this->normalizeTypePicks($request->input('columnTypes', []), $allowedTypes);

        if ($typePicks instanceof JsonResponse) {
            return $typePicks;
        }

        // Each source column feeds one target only: reject letters already
        // connected to a field (and letters used by two drafts).
        $usedLetters = collect($mapping)->filter(fn (string $letter): bool => $letter !== '')->values();

        foreach ($newColumns as $entry) {
            if ($usedLetters->contains($entry['letter'])) {
                return response()->json(['message' => 'Column '.$entry['letter'].' is already connected to a field. Each source column can be used once.'], 422);
            }

            $usedLetters->push($entry['letter']);
        }

        return [$mapping, $newColumns, $typePicks];
    }

    /**
     * Mapping sanity checks shared by execute() and prepare().
     *
     * @param  array<string, string>  $mapping
     */
    private function checkManagedMapping(array $mapping, array $targets): ?JsonResponse
    {
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

        return null;
    }

    /**
     * Validate + normalize one "＋ New column…" draft.
     *
     * @param  array<string, string>  $allowedTypes
     * @param  array<int, string>  $seenNames
     * @param  array<int, string>  $notices
     * @return array{letter: string, name: string, type: string, options: array|null}|JsonResponse
     */
    private function normalizeNewColumnEntry(array $entry, int $index, array $allowedTypes, array &$seenNames, array &$notices): array|JsonResponse
    {
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

        $type = $this->importColumnType($type, $name, $notices);
        $options = $this->normalizeOptions($entry['options'] ?? null, $type, $name);

        if ($options instanceof JsonResponse) {
            return $options;
        }

        $seenNames[] = $this->normalizeColumnName($name);

        return ['letter' => $letter, 'name' => $name, 'type' => $type, 'options' => $options];
    }

    /**
     * Step-3 type picks for columns connected to an EXISTING custom field.
     * Two payload shapes are accepted: the legacy `letter => type` string,
     * and `letter => {type, options}` — the wizard's option editor rides
     * along so a dropdown/status pick lands with the options the user
     * configured instead of the default starters. Fixed (managed) fields are
     * ignored downstream: their schema comes from the table, not the import.
     *
     * @param  array<string, string>  $allowedTypes
     * @return array<string, array{type: string, options: array<int, array{label: string, color: string}>|null}>|JsonResponse
     */
    private function normalizeTypePicks(mixed $raw, array $allowedTypes): array|JsonResponse
    {
        $normalized = [];

        foreach (is_array($raw) ? $raw : [] as $letter => $entry) {
            $letter = strtoupper(trim((string) $letter));
            $type = trim((string) (is_array($entry) ? ($entry['type'] ?? '') : $entry));

            if (! in_array($type, $allowedTypes, true)) {
                return response()->json(['message' => "Column {$letter}: unknown column type."], 422);
            }

            $options = is_array($entry) ? $this->normalizeOptions($entry['options'] ?? null, $type, $letter) : null;

            if ($options instanceof JsonResponse) {
                return $options;
            }

            $normalized[$letter] = ['type' => $type, 'options' => $options];
        }

        return $normalized;
    }

    /**
     * A column's own option list as {label, color} pairs, so a conversion
     * that arrives WITHOUT the step-3 editor payload keeps the options the
     * column already had instead of resetting them to the starters. Lenient
     * on shape (legacy plain strings, non-hex colors) — this runs on live
     * data a 422 would reject.
     *
     * @return array<int, array{label: string, color: string}>|null
     */
    private function existingOptionList(CustomTableColumn $column): ?array
    {
        $list = [];

        foreach ($column->settings['options'] ?? [] as $option) {
            if (is_array($option)) {
                $label = trim((string) ($option['label'] ?? ''));
                $color = trim((string) ($option['color'] ?? ''));
            } else {
                $label = trim((string) $option);
                $color = '';
            }

            if ($label === '') {
                continue;
            }

            $list[] = [
                'label' => $label,
                'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1
                    ? strtoupper($color)
                    : ImportOptionSeeder::COLORS[count($list) % count(ImportOptionSeeder::COLORS)],
            ];
        }

        return $list === [] ? null : $list;
    }

    /**
     * Resolve the column structure a managed import needs: connected-column
     * conversions, name-matched reuses, columns to create, and stale columns
     * to drop. Pure reads — nothing is written here.
     *
     * @param  array<string, string>  $mapping
     * @param  array<int, array{letter: string, name: string, type: string, options: array|null}>  $newColumns
     * @param  array<string, array{type: string, options: array|null}>  $typePicks
     * @param  array<int, string>  $notices
     * @return array{toCreate: array, toConvert: array, stale: Collection, nameMatched: array<string, string>}|JsonResponse
     */
    private function resolveManagedStructure(string $table, array $mapping, array $newColumns, array $typePicks, mixed $user, array &$notices): array|JsonResponse
    {
        // Name-based match: a draft whose name equals an existing custom
        // column of this table overwrites it instead of duplicating it.
        $existingColumns = CustomTableColumn::query()
            ->where('table_key', $table)
            ->get()
            ->keyBy(fn (CustomTableColumn $column): string => $this->normalizeColumnName($column->name));

        // Every mapped target that already owns a custom column, so a stale
        // one (kept from a previous file) can be told apart from a live one.
        $mappedColumnIds = collect($mapping)
            ->filter(fn (string $letter): bool => $letter !== '')
            ->keys()
            ->filter(fn (string $key): bool => str_starts_with($key, 'custom_'))
            ->map(fn (string $key): int => (int) substr($key, 7));

        $existingById = CustomTableColumn::query()
            ->where('table_key', $table)
            ->get()
            ->keyBy('id');

        $resolved = [];
        $toCreate = [];
        // column id => ['column' => model, 'type' => string, 'options' => ?array]
        $toConvert = [];

        // A type pick on a column already connected to a custom field
        // retypes that field — and, when the step-3 option editor sent its
        // list, stores those options too. Fixed (managed) fields are
        // ignored: their schema comes from the table, not the import.
        foreach ($mapping as $key => $letter) {
            if ($letter === '' || ! str_starts_with($key, 'custom_')) {
                continue;
            }

            $picked = $typePicks[$letter] ?? null;
            $column = $existingById->get((int) substr($key, 7));

            if ($picked === null || $column === null) {
                continue;
            }

            $type = $this->importColumnType($picked['type'], $column->name, $notices);
            $optionsSent = ($picked['options'] ?? null) !== null;

            // No editor payload: a conversion keeps the column's own
            // options instead of resetting them to the starters.
            $options = $optionsSent
                ? $picked['options']
                : (in_array($type, ['status', 'dropdown'], true) ? $this->existingOptionList($column) : null);

            // Retype when the pick differs; also re-save when the editor
            // sent a fresh option list for the type already in place.
            if ($type !== $column->type || $optionsSent) {
                $toConvert[$column->id] = ['column' => $column, 'type' => $type, 'options' => $options];
            }
        }

        foreach ($newColumns as $entry) {
            $existing = $existingColumns->get($this->normalizeColumnName($entry['name']));

            if ($existing !== null) {
                $resolved[$existing->columnKey()] = $entry['letter'];

                // Same name, different type: reuse must convert the column,
                // otherwise the matched type is silently dropped.
                if ($existing->type !== $entry['type']) {
                    $toConvert[$existing->id] = ['column' => $existing, 'type' => $entry['type'], 'options' => $entry['options']];
                }
            } else {
                $toCreate[] = $entry;
            }
        }

        // Columns this import keeps: name-matched drafts reuse their column,
        // so their ids survive the stale sweep below too.
        $resolvedKeys = collect(array_keys($resolved))
            ->filter(fn (string $key): bool => str_starts_with($key, 'custom_'))
            ->map(fn (string $key): int => (int) substr($key, 7));

        foreach ($resolved as $key => $letter) {
            if (($mapping[$key] ?? '') !== '' && $mapping[$key] !== $letter) {
                return response()->json(['message' => 'Column '.$letter.' and column '.$mapping[$key].' both connect to the same table field.'], 422);
            }
        }

        // Replace-all, structure-wise: the file defines the table's custom
        // columns, so a column left over from a previous file is dropped
        // rather than lingering forever (the purge only removes rows).
        $stale = $existingById
            ->reject(fn (CustomTableColumn $column, int $id): bool => $mappedColumnIds->contains($id) || $resolvedKeys->contains($id))
            ->keys();

        if (($toCreate !== [] || $toConvert !== [] || $stale->isNotEmpty()) && ! $user?->canEditRecords()) {
            return response()->json(['message' => 'Your role cannot add or change columns to this table.'], 403);
        }

        return ['toCreate' => $toCreate, 'toConvert' => $toConvert, 'stale' => $stale, 'nameMatched' => $resolved];
    }

    /**
     * Write the resolved column structure: purge rows first (like the
     * single-shot path, a rejected import must never leave structure
     * behind), drop stale columns, convert reused ones, create new ones.
     *
     * @return array<string, string>|JsonResponse target key => source letter additions
     */
    private function applyManagedStructure(string $table, mixed $stale, array $toConvert, array $toCreate, mixed $user): array|JsonResponse
    {
        try {
            app(ImportUndoService::class)->purgeTable($table);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // purgeTable already dropped every value; this only removes the
        // columns themselves. forceDelete: a soft-deleted column would still
        // come back through the table's column queries and be offered again
        // as a mapping target on the next import.
        if ($stale->isNotEmpty()) {
            CustomTableColumnValue::query()->whereIn('custom_column_id', $stale)->delete();
            CustomTableColumn::query()->whereIn('id', $stale)->forceDelete();
        }

        $resolved = [];

        // Reuse first, create after the purge so a rejected import never
        // leaves structure behind. Conversions run after the purge too:
        // purgeTable already deleted this column's values, so no stale
        // value can violate the new type.
        foreach ($toConvert as $conversion) {
            $conversion['column']->update([
                'type' => $conversion['type'],
                'settings' => $this->defaultColumnSettings($conversion['type'], $conversion['options']),
            ]);
        }

        $position = (int) CustomTableColumn::query()->where('table_key', $table)->max('position');

        foreach ($toCreate as $entry) {
            $column = CustomTableColumn::create([
                'table_key' => $table,
                'name' => $entry['name'],
                'type' => $entry['type'],
                'settings' => $this->defaultColumnSettings($entry['type'], $entry['options']),
                'position' => ++$position,
                'created_by' => $user?->id,
            ]);

            $resolved[$column->columnKey()] = $entry['letter'];
        }

        return $resolved;
    }

    /**
     * Quick import: build the step-3 payload from the file itself, using the
     * same precedence the wizard shows (recalled mapping first, then header
     * suggestions). Every header left over becomes a text column named by
     * its header — the file wins, nobody connects anything by hand.
     *
     * @param  array<string, string>  $signature  letter => header label
     * @return array{0: array<string, string>, 1: array<int, array{letter: string, name: string, type: string, options: null}>}
     */
    private function autoManagedPayload(array $targets, string $table, array $signature): array
    {
        $columns = [];

        foreach ($signature as $letter => $label) {
            $label = trim((string) $label);

            if (preg_match('/^[A-Z]{1,3}$/', (string) $letter) === 1 && $label !== '') {
                $columns[] = ['letter' => strtoupper((string) $letter), 'label' => $label];
            }
        }

        $service = app(ImportMappingService::class);
        $mapping = collect($targets['fields'])->mapWithKeys(fn (array $field): array => [$field['key'] => ''])->all();

        foreach ([$service->recallFor($targets['fields'], $table, $columns), $service->suggestMapping($targets['fields'], $columns)] as $set) {
            foreach ($set as $key => $letter) {
                if (($mapping[$key] ?? null) === '' && is_string($letter) && $letter !== '') {
                    $mapping[$key] = $letter;
                }
            }
        }

        $used = collect($mapping)->filter(fn (string $letter): bool => $letter !== '')->values()->all();
        $newColumns = [];
        $seenNames = [];

        foreach ($columns as $column) {
            if (in_array($column['letter'], $used, true)) {
                continue;
            }

            $name = mb_substr($column['label'], 0, 100);
            $normalized = $this->normalizeColumnName($name);

            if (in_array($normalized, $seenNames, true)) {
                // Two headers that normalize alike (e.g. "Cost Center" vs
                // "cost-center") still become two columns — the letter keeps
                // them apart instead of failing the import.
                $name = mb_substr($name.' ('.$column['letter'].')', 0, 100);
                $normalized = $this->normalizeColumnName($name);
            }

            $seenNames[] = $normalized;
            $used[] = $column['letter'];
            $newColumns[] = ['letter' => $column['letter'], 'name' => $name, 'type' => 'text', 'options' => null];
        }

        return [$mapping, $newColumns];
    }

    /**
     * Resumable import, step 1 of 3: validate the payload, apply the column
     * structure (purge, conversions, creates), then open a processing batch
     * the chunk calls below fill one short request at a time — a 500+ row
     * file can no longer die halfway as one long request.
     */
    public function prepare(Request $request, string $table)
    {
        abort_unless($request->user()?->canImport(), 403);

        ImportFatalCapture::register('classic.prepare:'.$table, emitJson: true);

        $request->validate([
            'uploadId' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'originalName' => ['required', 'string', 'max:255'],
            'sheet' => ['required', 'string', 'max:255'],
            'headerRow' => ['required', 'integer', 'min:1', 'max:200'],
            'dataStart' => ['required', 'integer', 'min:1', 'max:500'],
            'mapping' => ['array'],
            'newColumns' => ['sometimes', 'array'],
            'columnTypes' => ['sometimes', 'array'],
            'columnSignature' => ['sometimes', 'array'],
            'autoMap' => ['sometimes', 'boolean'],
        ]);

        $targets = ImportTargetResolver::for($table);

        if ($targets === null) {
            return response()->json(['message' => 'This table does not support imports.'], 422);
        }

        if ($targets['dynamic']) {
            return response()->json(['message' => 'Resumable import is not available for this table yet — use the single import button.'], 422);
        }

        $uploadId = $request->input('uploadId');
        $originalName = urldecode((string) $request->input('originalName'));
        $sheet = (string) $request->input('sheet');
        $headerRow = (int) $request->input('headerRow');
        $dataStart = (int) $request->input('dataStart');
        $signature = $this->columnSignature($request);
        $allowedTypes = array_keys($this->importableColumnTypes());
        $notices = [];

        if ($request->boolean('autoMap')) {
            [$mapping, $newColumns] = $this->autoManagedPayload($targets, $table, $signature);
            $typePicks = [];

            if ($mapping === [] && $newColumns === []) {
                return response()->json(['message' => 'No file columns were detected. Check the header row and try again.'], 422);
            }

            $required = collect($targets['fields'])
                ->filter(fn (array $field): bool => $field['required'] && ($mapping[$field['key']] ?? '') === '')
                ->pluck('label');

            if ($required->isNotEmpty()) {
                return response()->json(['message' => 'The file headers did not match the required field(s): '.$required->implode(', ').'. Open Customize columns to connect them.'], 422);
            }
        } else {
            $payload = $this->manualManagedPayload($request, $targets, $allowedTypes, $notices);

            if ($payload instanceof JsonResponse) {
                return $payload;
            }

            [$mapping, $newColumns, $typePicks] = $payload;
        }

        $structure = $this->resolveManagedStructure($table, $mapping, $newColumns, $typePicks, $request->user(), $notices);

        if ($structure instanceof JsonResponse) {
            return $structure;
        }

        $resolved = $this->applyManagedStructure($table, $structure['stale'], $structure['toConvert'], $structure['toCreate'], $request->user());

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $mapping = array_merge($mapping, $resolved, $structure['nameMatched']);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $relative = ImportStreamController::storedPathFor($uploadId, $extension);
        $absolute = Storage::disk(config('filesystems.default'))->path($relative);

        if (! is_file($absolute)) {
            return response()->json(['message' => 'The uploaded file is no longer on the server. Re-upload it.'], 422);
        }

        try {
            $batch = app(SourceWorkbookImportService::class)->beginMappedImport(
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

        if ($batch->status === 'failed') {
            $message = $batch->failures()->latest('id')->first()?->error_message
                ?? 'The import could not be started.';

            return response()->json(['message' => $message], 422);
        }

        $batch->metadata = array_merge($batch->metadata ?? [], [
            'notices' => $notices,
            'signature' => $signature,
        ]);
        $batch->save();

        return response()->json([
            'status' => $batch->status,
            'totalRows' => (int) $batch->total_rows,
            'batchId' => $batch->id,
            'chunkSize' => 250,
            'notices' => $notices,
        ]);
    }

    /**
     * Resumable import, step 2 of 3: write one row range. Each call is short
     * on purpose — when the host kills one, the wizard simply sends that
     * range again (completed ranges are skipped, row writes are idempotent).
     */
    public function chunk(Request $request, string $table)
    {
        abort_unless($request->user()?->canImport(), 403);

        ImportFatalCapture::register('classic.chunk:'.$table, emitJson: true);

        $request->validate([
            'batchId' => ['required', 'integer', 'min:1'],
            'offset' => ['required', 'integer', 'min:0'],
            'limit' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        $batch = $this->resumableBatch($table, (int) $request->input('batchId'));

        if ($batch instanceof JsonResponse) {
            return $batch;
        }

        if ($batch->status !== 'processing') {
            return response()->json(['message' => 'This import is no longer running.'], 422);
        }

        try {
            $result = app(SourceWorkbookImportService::class)->importMappedRange(
                $batch,
                (int) $request->input('offset'),
                (int) $request->input('limit'),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * Resumable import, step 3 of 3: close the batch once every data row is
     * written. Also the point the mapping is remembered for next time.
     */
    public function finish(Request $request, string $table)
    {
        abort_unless($request->user()?->canImport(), 403);

        $request->validate([
            'batchId' => ['required', 'integer', 'min:1'],
        ]);

        $batch = $this->resumableBatch($table, (int) $request->input('batchId'));

        if ($batch instanceof JsonResponse) {
            return $batch;
        }

        $batch->refresh();
        $remaining = app(SourceWorkbookImportService::class)->rowsRemaining($batch);

        if ($remaining > 0) {
            return response()->json(['message' => number_format($remaining).' row(s) are still unwritten — send the remaining chunks first.'], 422);
        }

        if ($batch->status !== 'processing') {
            return response()->json(['message' => 'This import is no longer running.'], 422);
        }

        $metadata = $batch->metadata ?? [];
        app(ImportMappingService::class)->rememberMapping($table, $metadata['mapping'] ?? [], $metadata['signature'] ?? []);

        $batch = app(SourceWorkbookImportService::class)->finishMappedImport($batch);

        return response()->json([
            'status' => $batch->status,
            'processed' => $batch->processed_rows,
            'failed' => $batch->failed_rows,
            'batchId' => $batch->id,
            'notices' => $metadata['notices'] ?? [],
        ]);
    }

    /**
     * Abandon a running resumable import. The batch is marked failed (never
     * stuck in processing), so Undo on the table page stays available for
     * the rows written so far.
     */
    public function cancel(Request $request, string $table)
    {
        abort_unless($request->user()?->canImport(), 403);

        $request->validate([
            'batchId' => ['required', 'integer', 'min:1'],
        ]);

        $batch = $this->resumableBatch($table, (int) $request->input('batchId'));

        if ($batch instanceof JsonResponse) {
            return $batch;
        }

        if ($batch->status !== 'processing') {
            return response()->json(['message' => 'This import is already finished.'], 422);
        }

        $batch->failures()->create([
            'error_type' => 'cancelled',
            'error_message' => 'Cancelled by the user. Rows written so far stay stamped with this batch.',
        ]);
        $batch->update(['status' => 'failed', 'completed_at' => now()]);
        TableAggregationService::invalidate();

        return response()->json(['status' => 'failed', 'batchId' => $batch->id]);
    }

    /**
     * Load a batch for the chunk/finish/cancel calls: it must exist, belong
     * to this table, and come from the resumable prepare() above (never a
     * legacy single-shot or auto-import batch).
     */
    private function resumableBatch(string $table, int $batchId): ImportBatch|JsonResponse
    {
        $batch = ImportBatch::query()->find($batchId);

        if ($batch === null || ! $this->batchBelongsTo($table, $batch)) {
            abort(404);
        }

        if (($batch->metadata['chunked_import'] ?? false) !== true) {
            return response()->json(['message' => 'This import was not started as a resumable import.'], 422);
        }

        return $batch;
    }

    /**
     * A workbook carries file *names*, never uploads, and the files type only
     * accepts stored file IDs — so every such cell failed and took its row
     * with it. Import those columns as text instead, so the data lands, and
     * say so in the result.
     *
     * @param  array<int, string>  $notices
     */
    private function importColumnType(string $type, string $columnName, array &$notices): string
    {
        if ($type !== 'files') {
            return $type;
        }

        $notices[] = "'{$columnName}' was imported as text — spreadsheets cannot carry file uploads.";

        return 'text';
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
        $notices = [];

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

            $options = $this->normalizeOptions($entry['options'] ?? null, $type, $name);

            if ($options instanceof JsonResponse) {
                return $options;
            }

            $type = $this->importColumnType($type, $name, $notices);
            $seenNames[] = mb_strtolower($name);
            $validated[] = ['letter' => $letter, 'name' => $name, 'type' => $type, 'options' => $options];
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
                'settings' => $this->defaultColumnSettings($entry['type'], $entry['options']),
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
            'notices' => $notices,
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
