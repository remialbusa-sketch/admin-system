<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Exports\ManagedTableExport;
use App\Http\Controllers\ImportStreamController;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\RecordEditLog;
use App\Models\TableColumnOption;
use App\Models\TableColumnPreference;
use App\Services\ColumnTypeRegistry;
use App\Services\ImportMappingService;
use App\Services\SourceWorkbookImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

abstract class ManagedTable extends Component
{
    use WithPagination;

    /**
     * Column types that can be safely edited inline from the grid today.
     * (files / location / person / formula need richer editors and are
     * rendered read-only for now.)
     */
    private const EDITABLE_CUSTOM_TYPES = [
        'text', 'long_text', 'number', 'checkbox', 'date', 'email', 'phone', 'link', 'status', 'dropdown',
    ];

    public string $search = '';

    /** URL-bound (as ?status=…) so dashboard/status drill-downs can deep-link. */
    #[Url(as: 'status')]
    public ?string $statusFilter = null;

    /** Stored relative path (storage/app/...) of the uploaded import workbook. */
    public ?string $importStoredPath = null;

    /** Workbook inspection: file type + sheet list. */
    public array $importAnalysis = [];

    /** Active sheet preview: header row, data start, columns, sample rows. */
    public array $importPreview = [];

    public string $importSheet = '';

    public int $importHeaderRow = 1;

    public int $importDataStart = 2;

    /** Manual mapping: target field key => source column letter ('' = unmapped). */
    public array $importMapping = [];

    public ?int $lastImportId = null;

    public array $importResult = [];

    /** Excel-style server-side sort state. */
    public ?string $sortField = null;

    public string $sortDirection = 'asc';

    /** Excel-style per-column filter state, keyed by column key. */
    public array $columnFilters = [];

    /** When true, the listing shows archived records instead of active ones. */
    public bool $showArchived = false;

    public bool $showColumnManagerModal = false;

    public string $newColumnName = '';

    public string $newColumnType = 'text';

    public ?int $renamingColumnId = null;

    public string $renamingColumnName = '';

    abstract public function model(): string;

    abstract public function columns(): array;

    abstract public function query(): Builder;

    public function canEdit(): bool
    {
        return auth()->user()?->role === UserRole::Superadmin;
    }

    /**
     * Exports contain the full customer/personnel dataset, so they are gated
     * to provisioned staff accounts (any role in the enum). This used to be
     * the one completely ungated mutation — it now fails closed for any
     * role-less account.
     */
    public function canExport(): bool
    {
        return auth()->user()?->role !== null;
    }

    /**
     * Active drill-down filters applied from a dashboard deep-link
     * (?status=…, ?brand=…). Subclasses override to surface them as
     * removable chips above the grid.
     *
     * @return array<string, string>
     */
    public function drillDownFilters(): array
    {
        return [];
    }

    public function clearDrillDown(): void
    {
        //
    }

    /**
     * Stable identifier used to scope custom columns / layout preferences
     * to this table. Defaults to the current route name for backward
     * compatibility, but subclasses should override this with a fixed
     * literal (matching the table's import key, e.g. 'installed-products') so it stays stable in
     * contexts without a bound HTTP route (e.g. Livewire component tests).
     */
    public function tableKey(): string
    {
        return $this->routeName() ?? static::class;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function setColumnFilter(string $field, ?string $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            unset($this->columnFilters[$field]);
        } else {
            $this->columnFilters[$field] = $value;
        }

        $this->resetPage();
    }

    public function clearColumnFilters(): void
    {
        $this->columnFilters = [];
        $this->resetPage();
    }

    /*
    |--------------------------------------------------------------------------
    | Selection actions (floating action bar / row context menu)
    |--------------------------------------------------------------------------
    */

    public function toggleShowArchived(): void
    {
        $this->showArchived = ! $this->showArchived;
        $this->resetPage();
    }

    /**
     * Whether the underlying table has the archived_at column (all managed
     * tables do since the archive migration; the check keeps per-table
     * subclasses and older installs safe).
     */
    protected function supportsArchive(): bool
    {
        $model = $this->model();

        return Schema::hasColumn((new $model)->getTable(), 'archived_at');
    }

    /** Scope a query to the currently active (or archived) record set. */
    protected function applyArchiveScope(Builder $query): Builder
    {
        if (! $this->supportsArchive()) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $this->showArchived
            ? $query->whereNotNull($table.'.archived_at')
            : $query->whereNull($table.'.archived_at');
    }

    /**
     * Duplicate the selected records (grid multi-select or row menu).
     * Copies every column, regenerates the source identity so the copy is
     * excluded from re-import matching, and copies custom column values.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int> created record ids, in input order
     */
    public function duplicateSelected(array $ids): array
    {
        abort_unless($this->canEdit(), 403);

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $model = $this->model();
        $created = [];

        foreach ($model::query()->whereKey($ids)->get() as $record) {
            // replicate() excludes the primary key; Eloquent stamps fresh
            // created_at / updated_at on save.
            $copy = $record->replicate();

            // The source identity keys the upsert on re-import; a duplicate
            // must never collide with (or be clobbered by) the original.
            if (array_key_exists('source_record_id', $record->getAttributes()) && $record->source_record_id !== null) {
                $copy->source_system = 'manual';
                $copy->source_record_id = $record->source_record_id.'-copy-'.strtolower(Str::random(8));
            }

            $copy->archived_at = null;
            $copy->save();

            $customColumnIds = CustomTableColumn::query()
                ->where('table_key', $this->tableKey())
                ->pluck('id');

            if ($customColumnIds->isNotEmpty()) {
                $values = CustomTableColumnValue::query()
                    ->whereIn('custom_column_id', $customColumnIds)
                    ->where('row_id', $record->getKey())
                    ->get();

                foreach ($values as $value) {
                    CustomTableColumnValue::query()->create([
                        'custom_column_id' => $value->custom_column_id,
                        'row_id' => $copy->getKey(),
                        'value' => $value->value,
                        'value_text' => $value->value_text,
                        'value_number' => $value->value_number,
                    ]);
                }
            }

            $this->logEdit((int) $copy->getKey(), 'duplicated', null, $record->getKey(), $copy->getKey());
            $created[] = (int) $copy->getKey();
        }

        return $created;
    }

    /**
     * Archive the selected records (hidden from the default listing,
     * restorable from the archive view).
     *
     * @param  array<int, int>  $ids
     */
    public function archiveSelected(array $ids): void
    {
        abort_unless($this->canEdit(), 403);

        if (! $this->supportsArchive()) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        $model = $this->model();
        $model::query()->whereKey($ids)->update(['archived_at' => now()]);

        foreach ($ids as $id) {
            $this->logEdit($id, 'archived');
        }
    }

    /**
     * Restore archived records back to the active listing.
     *
     * @param  array<int, int>  $ids
     */
    public function restoreSelected(array $ids): void
    {
        abort_unless($this->canEdit(), 403);

        if (! $this->supportsArchive()) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        $model = $this->model();
        $model::query()->whereKey($ids)->update(['archived_at' => null]);

        foreach ($ids as $id) {
            $this->logEdit($id, 'restored');
        }
    }

    public function updateField(int $id, string $field, mixed $value): void
    {
        abort_unless($this->canEdit(), 403);

        $rules = $this->rules();
        abort_unless(array_key_exists($field, $rules), 422);
        $validated = validator([$field => $value], [$field => $rules[$field]])->validate();
        $resolvedValue = $validated[$field] ?? null;

        $model = $this->model();
        $record = $model::query()->findOrFail($id);
        $old = $record->getAttribute($field);

        if (str_contains($field, '.')) {
            [$relationName, $relatedField] = explode('.', $field, 2);
            $related = $record->{$relationName};

            if ($related) {
                $old = $related->getAttribute($relatedField);
                $related->update([$relatedField => $resolvedValue]);
            }

            $this->logEdit($record->getKey(), 'updated', $field, $old, $resolvedValue);

            return;
        }

        $record->update([$field => $resolvedValue]);
        $this->logEdit($record->getKey(), 'updated', $field, $old, $resolvedValue);
    }

    /**
     * Write a value to a custom (user-defined) column for one row.
     */
    public function updateCustomField(int $rowId, int $customColumnId, mixed $value): void
    {
        abort_unless($this->canEdit(), 403);

        $column = CustomTableColumn::query()
            ->where('table_key', $this->tableKey())
            ->findOrFail($customColumnId);

        $type = app(ColumnTypeRegistry::class)->resolve($column->type);

        try {
            $validated = $type->validate($value, $column->settings ?? []);
        } catch (InvalidArgumentException $e) {
            $this->addError('customField', $e->getMessage());

            return;
        }

        $existing = CustomTableColumnValue::query()
            ->where('custom_column_id', $column->id)
            ->where('row_id', $rowId)
            ->first();
        $old = $existing?->value;

        $shadow = $type->toShadowFields($validated);

        CustomTableColumnValue::query()->updateOrCreate(
            ['custom_column_id' => $column->id, 'row_id' => $rowId],
            [
                'value' => $validated,
                'value_text' => $shadow['value_text'] ?? null,
                'value_number' => $shadow['value_number'] ?? null,
                'value_date' => $shadow['value_date'] ?? null,
            ],
        );

        $this->logEdit(
            $rowId,
            $existing ? 'updated' : 'created',
            $column->name,
            $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            json_encode($validated, JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Create a new record from a grid row payload (used by the "New item" button).
     *
     * Handles both direct fillable fields and relation fields expressed in dot
     * notation (e.g. "account.customer_name"). For relation fields it finds or
     * creates the related model and sets the foreign key.
     */
    public function createRecord(array $data): int
    {
        abort_unless($this->canEdit(), 403);

        $model = $this->model();
        $instance = new $model;
        $fillable = $instance->getFillable();
        $direct = [];
        $relations = [];

        foreach ($data as $key => $value) {
            if ($key === 'id') {
                continue;
            }

            if (str_contains($key, '.')) {
                [$relationName, $relatedField] = explode('.', $key, 2);
                $relations[$relationName][$relatedField] = $value;
            } elseif (in_array($key, $fillable, true)) {
                $direct[$key] = $value;
            }
        }

        foreach ($relations as $relationName => $fields) {
            $relation = $instance->{$relationName}();
            $relatedModel = $relation->getRelated();
            $firstField = array_key_first($fields);
            $firstValue = $fields[$firstField] ?? '';

            // Some related models (e.g. Account) have NOT NULL source_system /
            // source_record_id columns. Fill them with a stable generated value
            // so firstOrCreate doesn't violate the constraint.
            $relatedFillable = (new $relatedModel)->getFillable();
            if (in_array('source_system', $relatedFillable, true) && empty($fields['source_system'])) {
                $fields['source_system'] = 'manual';
            }
            if (in_array('source_record_id', $relatedFillable, true) && empty($fields['source_record_id'])) {
                $fields['source_record_id'] = 'manual-'.Str::slug((string) $firstValue).'-'.substr(md5((string) $firstValue), 0, 12);
            }

            $related = $relatedModel::query()->firstOrCreate(
                [$firstField => $firstValue],
                $fields,
            );
            $direct[$relation->getForeignKeyName()] = $related->getKey();
        }

        // The main model may also require source_system / source_record_id.
        if (in_array('source_system', $fillable, true) && empty($direct['source_system'])) {
            $direct['source_system'] = 'manual';
        }
        if (in_array('source_record_id', $fillable, true) && empty($direct['source_record_id'])) {
            $direct['source_record_id'] = 'manual-'.substr(md5(json_encode($direct)), 0, 24);
        }

        $record = $model::query()->create($direct);

        $this->logEdit($record->getKey(), 'created');

        return $record->getKey();
    }

    /**
     * Delete a record by id (used by the "Delete" action on a grid row).
     */
    public function deleteRecord(int $id): void
    {
        abort_unless($this->canEdit(), 403);

        $model = $this->model();
        $model::query()->findOrFail($id)->delete();

        $this->logEdit($id, 'deleted');
    }

    /**
     * Bulk delete multiple rows by primary key (from grid multi-select).
     * Mirrors deleteRecord() so the same authorization applies.
     *
     * @param  array<int, int>  $ids
     */
    public function deleteSelected(array $ids): void
    {
        abort_unless($this->canEdit(), 403);

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        $model = $this->model();
        $model::query()->whereKey($ids)->delete();

        foreach ($ids as $id) {
            $this->logEdit($id, 'deleted');
        }
    }

    /**
     * Append one entry to the manual-edit audit trail (who changed which
     * record, when). Import-driven changes are tracked by import batches;
     * this covers human edits for accountability.
     */
    private function logEdit(int $rowId, string $action, ?string $field = null, mixed $old = null, mixed $new = null): void
    {
        RecordEditLog::create([
            'user_id' => auth()->id(),
            'table_key' => $this->tableKey(),
            'row_id' => $rowId,
            'action' => $action,
            'field' => $field,
            'old_value' => $old === null ? null : (string) $old,
            'new_value' => $new === null ? null : (string) $new,
        ]);
    }

    /**
     * Persist a batch of grid edits in a single request.
     *
     * Each entry is either:
     *   - ['type' => 'create', 'data' => [...]]  -> create a new record
     *   - ['type' => 'update', 'id' => int, 'field' => string, 'value' => mixed]
     *   - ['type' => 'custom', 'id' => int, 'customId' => int, 'value' => mixed]
     *
     * Returns a map of tempId => realId for created rows so the frontend can
     * remap them. Doing this in one server call avoids Livewire 3.8.5's
     * request-batching bug ("Cannot read properties of undefined (reading
     * 'shift')") that occurs when many wire.call() requests fire in one tick.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<string, int>
     */
    public function saveGridChanges(string $changesJson): void
    {
        abort_unless($this->canEdit(), 403);

        $changes = json_decode($changesJson, true) ?: [];

        foreach ($changes as $change) {
            $type = $change['type'] ?? null;

            if ($type === 'create') {
                $this->createRecord($change['data'] ?? []);

                continue;
            }

            if ($type === 'update') {
                $this->updateField((int) $change['id'], (string) $change['field'], $change['value'] ?? null);

                continue;
            }

            if ($type === 'custom') {
                $this->updateCustomField((int) $change['id'], (int) $change['customId'], $change['value'] ?? null);

                continue;
            }

            if ($type === 'delete') {
                $this->deleteRecord((int) $change['id']);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Custom column management (add / rename / delete)
    |--------------------------------------------------------------------------
    */

    public function availableColumnTypes(): array
    {
        return array_keys(app(ColumnTypeRegistry::class)->all());
    }

    public function addCustomColumn(): void
    {
        abort_unless($this->canEdit(), 403);

        $this->validate([
            'newColumnName' => 'required|string|max:100',
            'newColumnType' => ['required', 'string', Rule::in($this->availableColumnTypes())],
        ]);

        $name = trim($this->newColumnName);

        $exists = CustomTableColumn::query()
            ->where('table_key', $this->tableKey())
            ->where('name', $name)
            ->exists();

        if ($exists) {
            $this->addError('newColumnName', 'A column with this name already exists on this table.');

            return;
        }

        $position = (int) CustomTableColumn::query()->where('table_key', $this->tableKey())->max('position') + 1;

        CustomTableColumn::create([
            'table_key' => $this->tableKey(),
            'name' => $name,
            'type' => $this->newColumnType,
            'settings' => $this->defaultColumnSettings($this->newColumnType),
            'position' => $position,
            'created_by' => auth()->id(),
        ]);

        $this->reset(['newColumnName']);
        $this->newColumnType = 'text';
    }

    public function startRenamingColumn(int $id, string $currentName): void
    {
        $this->renamingColumnId = $id;
        $this->renamingColumnName = $currentName;
    }

    public function renameCustomColumn(): void
    {
        abort_unless($this->canEdit(), 403);

        if ($this->renamingColumnId === null) {
            return;
        }

        $this->validate(['renamingColumnName' => 'required|string|max:100']);

        $column = CustomTableColumn::query()
            ->where('table_key', $this->tableKey())
            ->findOrFail($this->renamingColumnId);

        $column->update(['name' => trim($this->renamingColumnName)]);

        $this->reset(['renamingColumnId', 'renamingColumnName']);
    }

    public function deleteCustomColumn(int $id): void
    {
        abort_unless($this->canEdit(), 403);

        $column = CustomTableColumn::query()
            ->where('table_key', $this->tableKey())
            ->findOrFail($id);

        $column->delete();

        TableColumnPreference::query()
            ->where('table_key', $this->tableKey())
            ->where('column_key', $column->columnKey())
            ->delete();

        if ($this->sortField === $column->columnKey()) {
            $this->sortField = null;
        }

        unset($this->columnFilters[$column->columnKey()]);
    }

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
            'formula' => ['expression' => ''],
            default => [],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Column layout persistence (resize / reorder / freeze / hide)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, array{key: string, position?: int, width?: int|null, hidden?: bool, frozen?: bool}>  $layout
     */
    public function saveColumnLayout(array $layout): void
    {
        $userId = auth()->id();

        if (! $userId) {
            return;
        }

        foreach ($layout as $index => $entry) {
            $key = $entry['key'] ?? null;

            if (! $key) {
                continue;
            }

            TableColumnPreference::query()->updateOrCreate(
                ['user_id' => $userId, 'table_key' => $this->tableKey(), 'column_key' => $key],
                [
                    'position' => $entry['position'] ?? $index,
                    'width' => $entry['width'] ?? null,
                    'hidden' => (bool) ($entry['hidden'] ?? false),
                    'frozen' => (bool) ($entry['frozen'] ?? false),
                ],
            );
        }
    }

    public function resetColumnLayout(): void
    {
        $userId = auth()->id();

        if ($userId) {
            TableColumnPreference::query()
                ->where('user_id', $userId)
                ->where('table_key', $this->tableKey())
                ->delete();
        }
    }

    public function toggleColumnVisibility(string $key): void
    {
        $userId = auth()->id();

        if (! $userId) {
            return;
        }

        $pref = TableColumnPreference::query()->firstOrNew([
            'user_id' => $userId,
            'table_key' => $this->tableKey(),
            'column_key' => $key,
        ]);
        $pref->hidden = ! $pref->hidden;
        $pref->save();
    }

    public function toggleColumnFreeze(string $key): void
    {
        $userId = auth()->id();

        if (! $userId) {
            return;
        }

        $pref = TableColumnPreference::query()->firstOrNew([
            'user_id' => $userId,
            'table_key' => $this->tableKey(),
            'column_key' => $key,
        ]);
        $pref->frozen = ! $pref->frozen;
        $pref->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Column resolution (core + custom, ordered per user preference)
    |--------------------------------------------------------------------------
    */

    protected function customColumnModels(): Collection
    {
        return CustomTableColumn::query()
            ->where('table_key', $this->tableKey())
            ->orderBy('position')
            ->get();
    }

    protected function customColumnDefinitions(): array
    {
        return $this->customColumnModels()->map(fn (CustomTableColumn $column): array => [
            'key' => $column->columnKey(),
            'label' => $column->name,
            'type' => $column->type,
            'options' => $this->optionLabels($column->settings['options'] ?? []),
            'settings' => $column->settings ?? [],
            'custom' => true,
            'custom_id' => $column->id,
            'editable' => in_array($column->type, self::EDITABLE_CUSTOM_TYPES, true),
        ])->all();
    }

    private function optionLabels(array $options): array
    {
        if ($options === []) {
            return [];
        }

        if (isset($options[0]) && is_array($options[0])) {
            return array_map(fn (array $option): string => (string) ($option['label'] ?? ''), $options);
        }

        return $options;
    }

    public function allColumns(): array
    {
        $columns = array_merge(
            array_map(fn (array $column): array => $column + ['custom' => false, 'editable' => true], $this->columns()),
            $this->customColumnDefinitions(),
        );

        // Merge any user-added dropdown options (from the "+ New Label" button)
        // into select/status/dropdown columns.
        $stored = TableColumnOption::query()
            ->where('table_key', $this->tableKey())
            ->orderBy('position')
            ->get()
            ->groupBy('column_key');

        foreach ($columns as &$column) {
            if (! in_array($column['type'], ['select', 'status', 'dropdown'], true)) {
                continue;
            }

            $extra = $stored->get($column['key'], collect());
            $column['options'] = array_values(array_unique(array_merge($column['options'] ?? [], $extra->pluck('label')->all())));
            // Map label -> option id for stored (user-added) options so the
            // frontend can edit/delete them via the ellipsis menu.
            $column['optionIds'] = $extra->pluck('id', 'label')->all();
        }

        return $columns;
    }

    /**
     * Add a new option to a dropdown column (used by the "+ New Label" button
     * in the grid editor). Persisted for everyone (shared). Returns the new
     * option's id (or null if it already existed / was blank).
     */
    public function addColumnOption(string $columnKey, string $label): ?int
    {
        abort_unless($this->canEdit(), 403);

        // Skip the Livewire re-render: option mutations don't change grid data,
        // and re-rendering would destroy the open cell editor (causing a
        // Tabulator editorClear error).
        $this->skipRender();

        $label = trim($label);

        if ($label === '') {
            return null;
        }

        $exists = TableColumnOption::query()
            ->where('table_key', $this->tableKey())
            ->where('column_key', $columnKey)
            ->where('label', $label)
            ->exists();

        if ($exists) {
            return null;
        }

        $position = (int) TableColumnOption::query()
            ->where('table_key', $this->tableKey())
            ->where('column_key', $columnKey)
            ->max('position') + 1;

        $option = TableColumnOption::create([
            'table_key' => $this->tableKey(),
            'column_key' => $columnKey,
            'label' => $label,
            'position' => $position,
            'created_by' => auth()->id(),
        ]);

        return $option->id;
    }

    /**
     * Rename an existing dropdown option (used by the "Edit" action in the
     * label's ellipsis menu).
     */
    public function updateColumnOption(int $optionId, string $label): void
    {
        abort_unless($this->canEdit(), 403);

        // Skip the Livewire re-render so the open cell editor isn't destroyed.
        $this->skipRender();

        $label = trim($label);

        if ($label === '') {
            return;
        }

        $option = TableColumnOption::query()
            ->where('table_key', $this->tableKey())
            ->findOrFail($optionId);

        $duplicate = TableColumnOption::query()
            ->where('table_key', $this->tableKey())
            ->where('column_key', $option->column_key)
            ->where('label', $label)
            ->where('id', '!=', $option->id)
            ->exists();

        if ($duplicate) {
            return;
        }

        $option->update(['label' => $label]);
    }

    /**
     * Delete a dropdown option (used by the "Delete" action in the label's
     * ellipsis menu).
     */
    public function deleteColumnOption(int $optionId): void
    {
        abort_unless($this->canEdit(), 403);

        // Skip the Livewire re-render so the open cell editor isn't destroyed.
        $this->skipRender();

        TableColumnOption::query()
            ->where('table_key', $this->tableKey())
            ->findOrFail($optionId)
            ->delete();
    }

    protected function columnPreferences(): array
    {
        $userId = auth()->id();

        if (! $userId) {
            return [];
        }

        return TableColumnPreference::query()
            ->where('user_id', $userId)
            ->where('table_key', $this->tableKey())
            ->get()
            ->keyBy('column_key')
            ->all();
    }

    public function orderedColumns(): array
    {
        $columns = $this->allColumns();
        $prefs = $this->columnPreferences();

        usort($columns, function (array $a, array $b) use ($prefs): int {
            $posA = $prefs[$a['key']]->position ?? null;
            $posB = $prefs[$b['key']]->position ?? null;

            if ($posA === null && $posB === null) {
                return 0;
            }

            if ($posA === null) {
                return 1;
            }

            if ($posB === null) {
                return -1;
            }

            return $posA <=> $posB;
        });

        return array_map(function (array $column) use ($prefs): array {
            $pref = $prefs[$column['key']] ?? null;
            $column['width'] = $pref?->width;
            $column['hidden'] = (bool) ($pref?->hidden ?? false);
            $column['frozen'] = (bool) ($pref?->frozen ?? false);

            return $column;
        }, $columns);
    }

    /*
    |--------------------------------------------------------------------------
    | Query building: filters + sort
    |--------------------------------------------------------------------------
    */

    protected function rows()
    {
        $columns = $this->allColumns();
        $query = $this->query();
        $query = $this->applyArchiveScope($query);
        $query = $this->applyColumnFilters($query, $columns);
        $query = $this->applySort($query, $columns);

        return $query->paginate(50)->withQueryString();
    }

    protected function applyColumnFilters(Builder $query, array $columns): Builder
    {
        foreach ($this->columnFilters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $column = collect($columns)->firstWhere('key', $key);

            if (! $column) {
                continue;
            }

            if ($column['custom'] ?? false) {
                $this->applyCustomColumnFilter($query, $column, $value);

                continue;
            }

            $field = $column['key'];
            $type = $column['type'];

            if (str_contains($field, '.')) {
                [$relationName, $relatedField] = explode('.', $field, 2);
                $query->whereHas($relationName, function (Builder $relationQuery) use ($relatedField, $type, $value): void {
                    $this->applyFieldFilter($relationQuery, $relatedField, $type, $value);
                });

                continue;
            }

            $this->applyFieldFilter($query, $field, $type, $value);
        }

        return $query;
    }

    protected function applyFieldFilter(Builder $query, string $field, string $type, string $value): void
    {
        match ($type) {
            'select' => $query->where($field, $value),
            'number' => $query->where($field, $value),
            'date' => $query->whereDate($field, $value),
            default => $query->where($field, 'like', '%'.$value.'%'),
        };
    }

    protected function applyCustomColumnFilter(Builder $query, array $column, string $value): void
    {
        $table = $query->getModel()->getTable();
        $customId = $column['custom_id'];
        $isNumeric = in_array($column['type'], ['number', 'formula', 'checkbox'], true);

        $query->whereExists(function ($sub) use ($table, $customId, $isNumeric, $value): void {
            $sub->select('id')
                ->from('table_custom_column_values')
                ->whereColumn('table_custom_column_values.row_id', $table.'.id')
                ->where('table_custom_column_values.custom_column_id', $customId);

            if ($isNumeric) {
                $sub->where('table_custom_column_values.value_number', (float) $value);
            } else {
                $sub->where('table_custom_column_values.value_text', 'like', '%'.$value.'%');
            }
        });
    }

    protected function applySort(Builder $query, array $columns): Builder
    {
        $table = $query->getModel()->getTable();

        if (! $this->sortField) {
            return $query->orderByDesc($table.'.id');
        }

        $column = collect($columns)->firstWhere('key', $this->sortField);

        if (! $column) {
            return $query->orderByDesc($table.'.id');
        }

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        if ($column['custom'] ?? false) {
            $customId = $column['custom_id'];
            $alias = 'ctcv_sort_'.$customId;
            $isNumeric = in_array($column['type'], ['number', 'formula', 'checkbox'], true);
            $sortColumn = $isNumeric ? 'value_number' : 'value_text';

            $query->leftJoin("table_custom_column_values as {$alias}", function ($join) use ($alias, $customId, $table): void {
                $join->on("{$alias}.row_id", '=', $table.'.id')
                    ->where("{$alias}.custom_column_id", '=', $customId);
            })
                ->select($table.'.*')
                ->orderBy("{$alias}.{$sortColumn}", $direction);

            return $query;
        }

        $field = $this->sortField;

        if (str_contains($field, '.')) {
            [$relationName, $relatedField] = explode('.', $field, 2);
            $model = $query->getModel();

            if (! method_exists($model, $relationName)) {
                return $query->orderByDesc($table.'.id');
            }

            $relation = $model->{$relationName}();

            if (! $relation instanceof BelongsTo) {
                return $query->orderByDesc($table.'.id');
            }

            $relatedTable = $relation->getRelated()->getTable();

            $query->leftJoin($relatedTable, $relation->getQualifiedForeignKeyName(), '=', $relation->getQualifiedOwnerKeyName())
                ->select($table.'.*')
                ->orderBy($relatedTable.'.'.$relatedField, $direction);

            return $query;
        }

        return $query->orderBy($table.'.'.$field, $direction);
    }

    protected function rules(): array
    {
        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | Import (existing) / Export (new)
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Import wizard: upload -> manual column mapping -> official import
    |--------------------------------------------------------------------------
    |
    | The upload is inspected and previewed first; the official import only
    | ever runs from the mapping popup, with the user's hand-built mapping.
    */

    /**
     * Step 1: a streamed upload finished. The chunks were already assembled
     * on disk by ImportStreamController, so this only has to analyze the
     * workbook and build the preview - no multipart limits involved.
     */
    public function analyzeStreamedImport(string $uploadId, string $originalName): void
    {
        abort_unless($this->canEdit(), 403);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, ['xlsx', 'xls', 'csv', 'txt'], true)
            || preg_match('/^[a-f0-9]{32}$/', $uploadId) !== 1) {
            $this->addError('importFile', 'That file type is not supported. Use .xlsx, .xls or .csv.');

            return;
        }

        $this->reset(['importStoredPath', 'importAnalysis', 'importPreview', 'importSheet', 'importHeaderRow', 'importDataStart', 'importMapping', 'importResult', 'lastImportId']);

        $this->importStoredPath = ImportStreamController::storedPathFor($uploadId, $extension);
        $fullPath = $this->storedImportPath();

        if (! is_file($fullPath) || filesize($fullPath) === 0) {
            $this->reset(['importStoredPath', 'importAnalysis', 'importPreview', 'importSheet', 'importHeaderRow', 'importDataStart', 'importMapping']);
            $this->addError('importFile', 'The streamed upload did not reach the server. Try again.');

            return;
        }

        try {
            $mappingService = app(ImportMappingService::class);
            $this->importAnalysis = $mappingService->analyze($fullPath, $this->tableKey());
            $this->applyImportPreview($this->importAnalysis['preview']);
            $this->importMapping = $mappingService->blankMapping($this->tableKey());
        } catch (Throwable $exception) {
            // Never swallow the reason: report it and surface a short hint,
            // or a valid workbook mislabeled by the source system is
            // indistinguishable from a corrupt one.
            report($exception);

            $this->reset(['importStoredPath', 'importAnalysis', 'importPreview', 'importSheet', 'importHeaderRow', 'importDataStart', 'importMapping']);
            $this->addError('importFile', 'This file could not be read as an Excel or CSV workbook ('.Str::limit($exception->getMessage(), 140).'). Re-export it as .xlsx or .csv and try again.');
        }
    }

    /** Step 1: another sheet was picked - re-detect and preview it. */
    public function updatedImportSheet(): void
    {
        abort_unless($this->canEdit(), 403);

        if (! $this->importStoredPath || $this->importSheet === '') {
            return;
        }

        $this->refreshImportPreview(null, null);
    }

    /** Step 1: the header row moved - re-anchor the preview columns/samples. */
    public function updatedImportHeaderRow($value): void
    {
        abort_unless($this->canEdit(), 403);

        if (! $this->importStoredPath || $this->importSheet === '') {
            return;
        }

        $this->refreshImportPreview((int) $value, null);
    }

    /** Step 1: the first data row moved - re-anchor the preview samples. */
    public function updatedImportDataStart($value): void
    {
        abort_unless($this->canEdit(), 403);

        if (! $this->importStoredPath || $this->importSheet === '') {
            return;
        }

        $this->refreshImportPreview($this->importHeaderRow, (int) $value);
    }

    /** Step 2: open the mapping popup, pre-filling the last committed mapping. */
    public function openImportMapping(): void
    {
        abort_unless($this->canEdit(), 403);

        if (! $this->importStoredPath || ($this->importPreview['columns'] ?? []) === []) {
            return;
        }

        $this->importMapping = app(ImportMappingService::class)
            ->recallMapping($this->tableKey(), $this->importPreview['columns']);

        $this->dispatch('open-modal', name: 'import-mapping');
    }

    /**
     * Step 3: run the official import with the user's mapping. Validates the
     * mapping (required identity fields, distinct in-range columns), records
     * it on the batch, then hands workbook + mapping to the import service.
     */
    public function executeMappedImport(): void
    {
        abort_unless($this->canEdit(), 403);

        if (! $this->importStoredPath) {
            $this->addError('importFile', 'Choose a workbook first.');

            return;
        }

        $targets = ImportMappingService::TARGETS[$this->tableKey()] ?? null;

        if ($targets === null) {
            $this->addError('importMapping', 'This table does not support mapped imports.');

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
                $this->tableKey(),
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

        app(ImportMappingService::class)->rememberMapping($this->tableKey(), $mapping->all());

        $this->lastImportId = $batch->id;
        $this->importResult = [
            'status' => $batch->status,
            'processed' => $batch->processed_rows,
            'failed' => $batch->failed_rows,
        ];
    }

    /** Clear the whole wizard (file, preview, mapping, result). */
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

    /**
     * Absolute path of the stored import workbook. The default disk's root
     * is storage/app/private on the Laravel 11+ skeleton, so this must be
     * resolved through the filesystem disk - storage_path('app/...') never
     * matches where store() actually wrote the file.
     */
    private function storedImportPath(): string
    {
        return Storage::disk(config('filesystems.default'))->path((string) $this->importStoredPath);
    }

    public function exportExcel(): mixed
    {
        return $this->exportRows();
    }

    /**
     * Export only the selected rows (from the grid selection toolbar or the
     * per-row context menu) using the same columns/format as the full export.
     *
     * @param  array<int, int>  $ids
     */
    public function exportSelectedRows(array $ids): mixed
    {
        abort_unless($this->canExport(), 403);

        return $this->exportRows(array_values(array_filter(array_map('intval', $ids))));
    }

    private function exportRows(array $ids = []): mixed
    {
        abort_unless($this->canExport(), 403);

        $columns = collect($this->orderedColumns())
            ->reject(fn (array $column): bool => $column['hidden'] ?? false)
            ->values()
            ->all();

        $query = $this->query();
        $query = $this->applyArchiveScope($query);
        $query = $this->applyColumnFilters($query, $this->allColumns());
        $query = $this->applySort($query, $this->allColumns());

        if ($ids !== []) {
            $query->whereKey($ids);
        }

        // Hydrate in bounded chunks — the old unbounded ->get() pulled every
        // matching row (raw_data casts included) into memory at once.
        $registry = app(ColumnTypeRegistry::class);
        $exportRows = [];
        $query->chunk(500, function (Collection $records) use (&$exportRows, $columns, $registry): void {
            $customValues = $this->loadCustomValues($records, $columns);

            foreach ($records as $record) {
                $exportRows[] = array_map(function (array $column) use ($record, $customValues, $registry): string {
                    if ($column['custom'] ?? false) {
                        $value = $customValues->get($record->id)?->get($column['custom_id']);

                        return $this->safeExportValue(
                            $value?->value ? $registry->resolve($column['type'])->toDisplayString($value->value) : '',
                        );
                    }

                    $raw = $this->normalizeCoreValue(data_get($record, $column['key']), $column['type']);

                    return $raw === null ? '' : $this->safeExportValue((string) $raw);
                }, $columns);
            }
        });

        $headings = array_map(fn (array $column): string => $column['label'], $columns);

        $suffix = $ids === [] ? '' : '-selection';

        return Excel::download(
            new ManagedTableExport($headings, $exportRows, $this->title()),
            Str::slug($this->title()).$suffix.'-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /**
     * Neutralize spreadsheet formula injection: values beginning with =, +, -,
     *
     * @, tab or CR would be evaluated as formulas when the exported workbook is
     * opened in Excel. Prefix with an apostrophe so they render as text.
     */
    private function safeExportValue(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * Reduce Carbon/DateTime instances (from Eloquent date casts) to plain
     * strings so they serialize cleanly to both the export sheet and the
     * grid's JSON payload.
     */
    protected function normalizeCoreValue(mixed $raw, string $type): mixed
    {
        if ($raw instanceof \DateTimeInterface) {
            return $type === 'date' ? $raw->format('Y-m-d') : $raw->format('Y-m-d H:i:s');
        }

        return $raw;
    }

    /**
     * @return Collection<int, Collection<int, CustomTableColumnValue>> keyed by row id, then by custom_column_id
     */
    protected function loadCustomValues(Collection $records, array $columns): Collection
    {
        $customColumnIds = collect($columns)
            ->filter(fn (array $column): bool => $column['custom'] ?? false)
            ->pluck('custom_id')
            ->all();

        if ($customColumnIds === [] || $records->isEmpty()) {
            return collect();
        }

        return CustomTableColumnValue::query()
            ->whereIn('custom_column_id', $customColumnIds)
            ->whereIn('row_id', $records->pluck('id')->all())
            ->get()
            ->groupBy('row_id')
            ->map(fn (Collection $group): Collection => $group->keyBy('custom_column_id'));
    }

    /*
    |--------------------------------------------------------------------------
    | Grid payload (JSON handed to the Tabulator-based frontend)
    |--------------------------------------------------------------------------
    */

    protected function gridPayload($rows, array $columns): array
    {
        $records = collect($rows->items());
        $customValues = $this->loadCustomValues($records, $columns);

        $gridRows = $records->map(function ($record) use ($columns, $customValues): array {
            $out = ['id' => $record->id, 'archived' => $record->archived_at !== null];

            foreach ($columns as $column) {
                if ($column['custom'] ?? false) {
                    $value = $customValues->get($record->id)?->get($column['custom_id']);
                    $out[$column['key']] = $value?->value ? $this->customRawValueForEditing($column['type'], $value->value) : null;
                } else {
                    $out[$column['key']] = $this->normalizeCoreValue(data_get($record, $column['key']), $column['type']);
                }
            }

            return $out;
        })->all();

        return [
            'columns' => array_map(fn (array $column): array => [
                'key' => $column['key'],
                'label' => $column['label'],
                'type' => $column['type'],
                'options' => $column['options'] ?? [],
                'optionIds' => $column['optionIds'] ?? [],
                'settings' => $column['settings'] ?? [],
                'width' => $column['width'] ?? null,
                'hidden' => $column['hidden'] ?? false,
                'frozen' => $column['frozen'] ?? false,
                'custom' => $column['custom'] ?? false,
                'customId' => $column['custom_id'] ?? null,
                'editable' => $column['editable'] ?? true,
            ], $columns),
            'rows' => $gridRows,
            'meta' => [
                'total' => $rows->total(),
                'currentPage' => $rows->currentPage(),
                'lastPage' => $rows->lastPage(),
                'perPage' => $rows->perPage(),
                'editable' => $this->canEdit(),
                'showArchived' => $this->showArchived,
                'sortField' => $this->sortField,
                'sortDirection' => $this->sortDirection,
                'columnFilters' => $this->columnFilters,
            ],
        ];
    }

    private function customRawValueForEditing(string $type, array $value): mixed
    {
        return match ($type) {
            'text', 'long_text' => $value['text'] ?? null,
            'email' => $value['email'] ?? null,
            'phone' => $value['phone'] ?? null,
            'link' => $value['url'] ?? null,
            'number', 'formula' => $value['number'] ?? null,
            'checkbox' => (bool) ($value['checked'] ?? false),
            'date' => $value['date'] ?? null,
            'status' => $value['label'] ?? null,
            'dropdown' => $value['labels'][0] ?? null,
            default => null,
        };
    }

    protected function routeName(): string
    {
        return request()->route()?->getName() ?? static::class;
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

        return view('livewire.managed-table', [
            'rows' => $rows,
            'columns' => $columns,
            'editable' => $this->canEdit(),
            'showArchived' => $this->showArchived,
            'archivedCount' => $this->supportsArchive()
                ? $this->model()::query()->whereNotNull('archived_at')->count()
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
        ])->layout('layouts.dashboard')->title($this->title());
    }

    protected function statusOptions(): array
    {
        return [];
    }

    /**
     * The mappable import columns for this table (drives the import-wizard
     * mapping popup). Subclasses for dynamic/user-created tables override this
     * to build targets from their custom columns.
     */
    protected function importTargets(): ?array
    {
        return ImportMappingService::TARGETS[$this->tableKey()] ?? null;
    }

    protected function title(): string
    {
        return 'Managed table';
    }

    protected function description(): string
    {
        return '';
    }
}
