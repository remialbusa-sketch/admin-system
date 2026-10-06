<?php

namespace App\Livewire;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable as DynamicTableModel;
use App\Services\ColumnTypeRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * A user-created table. Extends ManagedTable with the generic DynamicRow store
 * as the source; every column a user defines while creating the table (or adds
 * later) is a table_custom_column keyed by the dynamic table's key.
 *
 * The monday.com connect menu / live-pull toggle comes from the shared
 * ConnectsMondayBoard trait (via ManagedTable); this class only mirrors the
 * board id onto its registry column for display (TablesList) alongside the
 * canonical monday_sync_settings row.
 */
class DynamicTable extends ManagedTable
{
    // Public so Livewire persists them across component calls (hydration only
    // covers public properties; protected props reset to defaults on each call).
    public string $dynamicKey = '';

    public string $dynamicName = 'Table';

    public string $dynamicDescription = '';

    /**
     * Fresh registry row for this request's permission checks — never
     * trusted across requests (Livewire resets private props per call).
     */
    private ?DynamicTableModel $registryCache = null;

    private function registry(): ?DynamicTableModel
    {
        return $this->registryCache ??= DynamicTableModel::query()
            ->where('key', $this->dynamicKey)
            ->first();
    }

    public function mount(string $table): void
    {
        $registry = DynamicTableModel::query()->where('key', $table)->firstOrFail();

        // Personal tables are private: owner, superadmin, or a share only.
        abort_unless($registry->canBeViewedBy(auth()->user()), 403);

        $this->registryCache = $registry;
        $this->dynamicKey = $registry->key;
        $this->dynamicName = $registry->name;
        $this->dynamicDescription = (string) $registry->description;

        // Connection state from the canonical setting row; fall back to the
        // registry copy for rows written before the setting existed.
        $this->hydrateMondayState();
        $this->mondayBoardId ??= $registry->monday_board_id;
    }

    /**
     * Row/column mutations need the global edit role AND table-level edit
     * (owner/superadmin/edit share) — a view-only share never writes.
     */
    public function canEdit(): bool
    {
        return parent::canEdit()
            && $this->registry()?->canBeEditedBy(auth()->user()) === true;
    }

    /** Importing/live-pull writes rows too: same table-level edit bar. */
    public function canImport(): bool
    {
        return parent::canImport()
            && $this->registry()?->canBeEditedBy(auth()->user()) === true;
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
    | monday.com connection (shared trait) — dynamic-specific hooks
    |--------------------------------------------------------------------------
    */

    /**
     * Keep the registry copy in sync with the canonical setting row so
     * TablesList and legacy readers still see the connected board.
     */
    protected function persistMondayBoard(?string $boardId): void
    {
        parent::persistMondayBoard($boardId);

        $this->registry()?->update(['monday_board_id' => $boardId]);
    }

    public function render(): View
    {
        // Re-check on every Livewire request: mount only runs on the first,
        // so a revoked share must still close the page here.
        abort_unless($this->registry()?->canBeViewedBy(auth()->user()) === true, 403);

        $rows = $this->rows();
        $columns = $this->orderedColumns();

        return view('livewire.dynamic-table', [
            'rows' => $rows,
            'columns' => $columns,
            'editable' => $this->canEdit(),
            'canImport' => $this->canImport(),
            'showArchived' => $this->showArchived,
            'archivedCount' => $this->supportsArchive()
                ? DynamicRow::query()->where('table_key', $this->dynamicKey)->whereNotNull('archived_at')->count()
                : 0,
            'statuses' => $this->statusOptions(),
            'title' => $this->title(),
            'description' => $this->description(),
            'tableKey' => $this->tableKey(),
            'columnTypeOptions' => app(ColumnTypeRegistry::class)->all(),
            'customColumns' => $this->customColumnModels(),
            'gridPayload' => $this->gridPayload($rows, $columns),
            // monday connect panel state (shared partial).
            'monday' => $this->mondayViewData(),
        ])->layout('layouts.dashboard')->title($this->title());
    }
}
