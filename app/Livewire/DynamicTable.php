<?php

namespace App\Livewire;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable as DynamicTableModel;
use App\Models\MondaySyncSetting;
use App\Services\ColumnTypeRegistry;
use App\Services\DynamicTableImportService;
use App\Services\MondayApiClient;
use App\Services\MondayItemMapper;
use App\Services\MondaySyncService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

/**
 * A user-created table. Extends ManagedTable with the generic DynamicRow store
 * as the source; every column a user defines while creating the table (or adds
 * later) is a table_custom_column keyed by the dynamic table's key.
 *
 * Also hosts the per-table monday.com live-pull toggle (M-DT) and the
 * board-connect / backfill-import entry points.
 */
class DynamicTable extends ManagedTable
{
    // Public so Livewire persists them across component calls (hydration only
    // covers public properties; protected props reset to defaults on each call).
    public string $dynamicKey = '';

    public string $dynamicName = 'Table';

    public string $dynamicDescription = '';

    public ?string $mondayBoardId = null;

    public bool $mondayEnabled = false;

    public bool $showConnectBoardModal = false;

    public string $connectBoardId = '';

    public function mount(string $table): void
    {
        $registry = DynamicTableModel::query()->where('key', $table)->firstOrFail();

        $this->dynamicKey = $registry->key;
        $this->dynamicName = $registry->name;
        $this->dynamicDescription = (string) $registry->description;
        $this->mondayBoardId = $registry->monday_board_id;

        $this->mondayEnabled = (bool) MondaySyncSetting::forDomain($this->dynamicKey)->enabled;
    }

    public function tableKey(): string
    {
        return $this->dynamicKey;
    }

    public function model(): string
    {
        return DynamicRow::class;
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function query(): Builder
    {
        return DynamicRow::query()
            ->where('table_key', $this->dynamicKey)
            ->when($this->search !== '', function (Builder $query): void {
                $like = '%'.trim($this->search).'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->where('name', 'like', $like);
                });
            });
    }

    protected function title(): string
    {
        return $this->dynamicName;
    }

    protected function description(): string
    {
        return $this->dynamicDescription;
    }

    /*
    |--------------------------------------------------------------------------
    | Row lifecycle (dynamic row store)
    |--------------------------------------------------------------------------
    */

    public function createRecord(array $data): int
    {
        abort_unless($this->canEdit(), 403);

        $data['table_key'] = $this->dynamicKey;
        $data['name'] = trim((string) ($data['name'] ?? '')) !== '' ? (string) $data['name'] : 'Untitled';
        $data['source_system'] = 'manual';
        $data['source_record_id'] = 'manual-'.substr(md5($data['name'].microtime()), 0, 24);

        return parent::createRecord($data);
    }

    public function deleteRecord(int $id): void
    {
        abort_unless($this->canEdit(), 403);

        $this->purgeCustomValuesFor([$id]);
        parent::deleteRecord($id);
    }

    public function deleteSelected(array $ids): void
    {
        abort_unless($this->canEdit(), 403);

        $ids = array_values(array_filter(array_map('intval', $ids)));
        $this->purgeCustomValuesFor($ids);
        parent::deleteSelected($ids);
    }

    /**
     * table_custom_column_values.row_id has no FK (pivot's choice, same as the
     * managed tables), so purge the row's custom values manually on delete.
     *
     * @param  array<int, int>  $rowIds
     */
    private function purgeCustomValuesFor(array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        $columnIds = CustomTableColumn::query()
            ->where('table_key', $this->dynamicKey)
            ->pluck('id');

        if ($columnIds->isEmpty()) {
            return;
        }

        CustomTableColumnValue::query()
            ->whereIn('row_id', $rowIds)
            ->whereIn('custom_column_id', $columnIds)
            ->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Backfill import (dynamic table column mapping)
    |--------------------------------------------------------------------------
    */

    protected function importTargets(): ?array
    {
        $fields = collect(CustomTableColumn::query()
            ->where('table_key', $this->dynamicKey)
            ->orderBy('position')
            ->get(['id', 'name', 'type']))
            ->map(fn (CustomTableColumn $column): array => [
                'key' => $column->columnKey(),
                'label' => $column->name,
                'required' => false,
                'kind' => $column->type,
            ])
            ->all();

        return [
            'label' => $this->dynamicName,
            'fields' => [
                ['key' => '__identity__', 'label' => 'Identity (Item ID)', 'required' => false, 'kind' => 'text'],
                ['key' => 'name', 'label' => 'Name', 'required' => false, 'kind' => 'text'],
                ...$fields,
            ],
        ];
    }

    public function openImportMapping(): void
    {
        abort_unless($this->canEdit(), 403);

        if (! $this->importStoredPath || ($this->importPreview['columns'] ?? []) === []) {
            return;
        }

        $targets = $this->importTargets()['fields'] ?? [];
        $this->importMapping = collect($targets)->mapWithKeys(fn (array $field): array => [$field['key'] => ''])->all();

        $this->dispatch('open-modal', name: 'import-mapping');
    }

    public function executeMappedImport(): void
    {
        abort_unless($this->canEdit(), 403);

        if (! $this->importStoredPath) {
            $this->addError('importMapping', 'Choose a workbook first.');

            return;
        }

        $targets = $this->importTargets()['fields'] ?? [];
        $validLetters = collect($this->importPreview['columns'] ?? [])->pluck('letter')->flip();

        $mapping = collect($this->importMapping)
            ->map(fn ($letter): string => strtoupper(trim((string) $letter)))
            ->filter(fn (string $letter): string => $letter !== '');

        $mappedValid = $mapping->filter(fn (string $letter): string => (string) $validLetters->get($letter, '') === $letter);

        if ($mappedValid->isEmpty()) {
            $this->addError('importMapping', 'Map at least one column before importing.');

            return;
        }

        $targetKeys = collect($targets)->pluck('key')->flip();
        $badTargets = $mapping->keys()->reject(fn (string $key): bool => $targetKeys->has($key));

        if ($badTargets->isNotEmpty()) {
            $this->addError('importMapping', 'Unknown import target.');

            return;
        }

        try {
            $batch = app(DynamicTableImportService::class)->import(
                $this->storedImportPath(),
                $this->dynamicKey,
                $this->importSheet,
                $mapping->all(),
                $this->importHeaderRow,
                max($this->importHeaderRow + 1, $this->importDataStart),
                auth()->id(),
            );
        } catch (Throwable $exception) {
            $this->addError('importMapping', Str::limit($exception->getMessage(), 200));

            return;
        }

        session()->put('import-mapping:'.$this->dynamicKey, $mapping->all());

        $this->lastImportId = $batch->id;
        $this->importResult = [
            'status' => $batch->status,
            'processed' => $batch->processed_rows,
            'failed' => $batch->failed_rows,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | monday.com live-pull toggle + board connect (M-DT entry points)
    |--------------------------------------------------------------------------
    */

    public function toggleMondayPull(): void
    {
        abort_unless($this->canEdit(), 403);

        $setting = MondaySyncSetting::forDomain($this->dynamicKey);
        $setting->update(['enabled' => ! $setting->enabled]);
        $this->mondayEnabled = $setting->enabled;

        session()->flash('mondayMessage', $this->mondayEnabled
            ? 'Live pull for new monday.com items is now ON (next sync picks up new items).'
            : 'Live pull for new monday.com items is now OFF.');
    }

    /**
     * Manually run the new-item pull for this table now (Superadmin escape
     * hatch when the scheduler isn't running or an operator wants an immediate
     * sync). Validates the global flag + token, then reports what happened.
     */
    public function syncNow(): void
    {
        abort_unless($this->canEdit(), 403);

        if (! config('monday.enabled', false)) {
            session()->flash('mondayMessage', 'monday sync is disabled globally (MONDAY_SYNC_ENABLED=false). Enable it to pull new items.');

            return;
        }

        try {
            $result = app(MondaySyncService::class)->syncDomain($this->dynamicKey);
        } catch (Throwable $exception) {
            session()->flash('mondayMessage', 'Sync failed: '.$exception->getMessage());

            return;
        }

        $message = match ($result['status']) {
            'disabled' => $result['message'] ?? 'Sync is not ready.',
            'error' => $result['message'] ?? 'Sync errored.',
            default => sprintf(
                'Sync done — %d new item(s), %d imported, %d failed.',
                (int) ($result['new'] ?? 0),
                (int) ($result['imported'] ?? 0),
                (int) ($result['failed'] ?? 0),
            ),
        };

        session()->flash('mondayMessage', $message);
    }

    public function openConnectBoard(): void
    {
        abort_unless($this->canEdit(), 403);

        $this->connectBoardId = (string) ($this->mondayBoardId ?? '');
        $this->showConnectBoardModal = true;
    }

    /**
     * Connect a monday.com board to this table: store the board id + sync
     * setting, then AUTO-CREATE the board's real columns (the fast auto-map)
     * and persist the field map so live-pulled items land in the right columns.
     */
    public function connectBoard(): void
    {
        abort_unless($this->canEdit(), 403);

        $boardId = trim($this->connectBoardId);

        if ($boardId === '') {
            $this->addError('connectBoardId', 'Enter the monday.com board id.');

            return;
        }

        $registry = DynamicTableModel::query()->where('key', $this->dynamicKey)->firstOrFail();
        $registry->update(['monday_board_id' => $boardId]);
        $this->mondayBoardId = $boardId;

        MondaySyncSetting::forDomain($this->dynamicKey)->update(['board_id' => $boardId]);

        $created = 0;

        try {
            $boardColumns = app(MondayApiClient::class)->boardColumns((int) $boardId);
            $created = app(MondayItemMapper::class)->autoCreateColumns($registry, $boardColumns);
        } catch (Throwable $exception) {
            $this->showConnectBoardModal = false;
            session()->flash('mondayMessage', 'Board '.$boardId.' saved, but fetching its columns failed: '.$exception->getMessage());

            return;
        }

        $this->showConnectBoardModal = false;
        session()->flash('mondayMessage', 'Board '.$boardId.' connected — '.$created.' column(s) auto-created to match the board.');
    }

    public function render(): View
    {
        $rows = $this->rows();
        $columns = $this->orderedColumns();
        $importTargets = $this->importTargets();
        $importMissingRequired = collect($importTargets['fields'] ?? [])
            ->filter(fn (array $field): bool => $field['required'] && trim((string) ($this->importMapping[$field['key']] ?? '')) === '')
            ->pluck('label')
            ->values()
            ->all();

        // Last-sync state for the monday panel (display-only, no API call).
        $setting = MondaySyncSetting::forDomain($this->dynamicKey);

        return view('livewire.dynamic-table', [
            'rows' => $rows,
            'columns' => $columns,
            'editable' => $this->canEdit(),
            'showArchived' => $this->showArchived,
            'archivedCount' => $this->supportsArchive()
                ? DynamicRow::query()->where('table_key', $this->dynamicKey)->whereNotNull('archived_at')->count()
                : 0,
            'statuses' => $this->statusOptions(),
            'importTargets' => $importTargets,
            'importMappedCount' => collect($this->importMapping)->filter(fn ($letter): bool => trim((string) $letter) !== '')->count(),
            'importMissingRequired' => $importMissingRequired,
            'importResult' => $this->importResult,
            'title' => $this->title(),
            'description' => $this->description(),
            'tableKey' => $this->tableKey(),
            'columnTypeOptions' => app(ColumnTypeRegistry::class)->all(),
            'customColumns' => $this->customColumnModels(),
            'gridPayload' => $this->gridPayload($rows, $columns),
            // monday panel
            'mondayBoardId' => $this->mondayBoardId,
            'mondayEnabled' => $this->mondayEnabled,
            'mondayGlobalEnabled' => config('monday.enabled', false),
            'mondayLastSyncedAt' => $setting->last_synced_at,
            'mondayLastItemId' => $setting->last_item_id_seen,
        ])->layout('layouts.dashboard')->title($this->title());
    }
}
