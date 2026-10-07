<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\Installation;
use App\Models\MondaySyncSetting;
use App\Support\ImportOptionSeeder;
use App\Support\MondayCoreTargets;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * M-DC: maps monday.com items into ANY table — a user-created (dynamic)
 * table or one of the five core domain tables.
 *
 * Responsibilities:
 *   1. autoCreateColumns() — given the board's real columns (from
 *      monday:inspect-board or the connect flow), create missing
 *      table_custom_columns for the domain and persist the monday column id
 *      -> custom column id map in monday_sync_settings.field_map (canonical
 *      home; dynamic tables also mirror it onto their registry column for
 *      legacy readers).
 *   2. mapItem() — upsert the domain row (DynamicRow for dynamic tables, the
 *      fixed domain model for core tables; source_system = monday:<key>,
 *      source_record_id = monday item id) and write each mapped column value
 *      through the registry, exactly like a manual cell write.
 *
 * Status/dropdown values seed their options through ImportOptionSeeder first
 * (the shared import boundary) so board labels never fail validation.
 */
class MondayItemMapper
{
    /** monday column type -> ColumnTypeRegistry key. */
    private const TYPE_MAP = [
        'text' => 'text',
        'long_text' => 'long_text',
        'numbers' => 'number',
        'status' => 'status',
        'date' => 'date',
        'email' => 'email',
        'phone' => 'phone',
        'link' => 'link',
        'checkbox' => 'checkbox',
        'dropdown' => 'dropdown',
        'people' => 'person',
        'location' => 'location',
        'files' => 'files',
        'formula' => 'text', // mirror/formula/flat types -> kept as text
        'mirror' => 'text',
        'color' => 'text',
        'priority' => 'text',
        'world_clock' => 'text',
        'timeline' => 'text',
        'tags' => 'text',
        'country' => 'text',
        'board' => 'text',
        'team' => 'text',
        'hour' => 'text',
        'auto_number' => 'number',
        'item_assignees' => 'person',
        'button' => 'text',
        'integration' => 'text',
        'money' => 'number',
        'rating' => 'number',
        'document' => 'text',
        'progress' => 'number',
    ];

    public function __construct(protected ColumnTypeRegistry $registry) {}

    /**
     * The registry key for a monday column type (defaults to text).
     */
    public function registryTypeFor(string $mondayType): string
    {
        return self::TYPE_MAP[strtolower($mondayType)] ?? 'text';
    }

    /**
     * Map the board's real columns onto this table — creating the missing
     * ones — and persist the field map (monday column id -> custom column
     * id). Existing entries are kept; board columns win on conflict.
     *
     * @param  array<int, array{id: string, title: string, type: string}>  $boardColumns
     */
    public function autoCreateColumns(string $tableKey, array $boardColumns, ?int $createdBy = null): int
    {
        $createdBy ??= auth()->user()?->id;

        $known = $this->columnsByName($tableKey);

        $fieldMap = [];
        $created = 0;

        foreach ($boardColumns as $column) {
            $id = (string) ($column['id'] ?? '');
            $title = trim((string) ($column['title'] ?? ''));

            if ($id === '' || $title === '') {
                continue;
            }

            $local = $this->resolveColumn($tableKey, $column, $createdBy, $known);

            $fieldMap[$id] = $local->id;

            if ($local->wasRecentlyCreated) {
                $created++;
            }
        }

        $setting = MondaySyncSetting::forDomain($tableKey);
        $this->persistFieldMap($tableKey, array_merge($setting->field_map ?? [], $fieldMap));

        return $created;
    }

    /**
     * Resolve ONE board column onto this table: reuse the column with the
     * same name or create it from the board column's type + dropdown
     * options. Shared by the connect-time auto-map and the Map-columns
     * editor so the two paths cannot drift.
     *
     * @param  array{title?: string, type?: string, settings_str?: string}  $boardColumn
     */
    public function ensureColumn(string $tableKey, array $boardColumn, ?int $createdBy = null): CustomTableColumn
    {
        return $this->resolveColumn($tableKey, $boardColumn, $createdBy, $this->columnsByName($tableKey));
    }

    /**
     * Persist the FULL field map for a domain: canonical home
     * (monday_sync_settings.field_map) plus the dynamic table's legacy
     * mirror, kept equal to it so the two can never disagree.
     */
    public function persistFieldMap(string $tableKey, array $fieldMap): void
    {
        MondaySyncSetting::forDomain($tableKey)->update(['field_map' => $fieldMap]);

        if ($table = DynamicTable::query()->where('key', $tableKey)->first()) {
            $table->update(['monday_field_map' => $fieldMap]);
        }
    }

    /**
     * Map onto an existing column instead of duplicating it. The unique key
     * (table_key, name) is case-insensitive in MySQL and counts soft-deleted
     * rows, so the lookup must be too: match on a lowercased key, trashed
     * rows included (restored — the board wants the column live again).
     *
     * @param  Collection<string, CustomTableColumn>  $known  nameKey => column; a created column is added so duplicate board titles map instead of colliding.
     */
    private function resolveColumn(string $tableKey, array $boardColumn, ?int $createdBy, Collection $known): CustomTableColumn
    {
        $title = trim((string) ($boardColumn['title'] ?? ''));
        $key = $this->nameKey($title);

        $existingColumn = $known->get($key);

        if ($existingColumn !== null) {
            if ($existingColumn->trashed()) {
                $existingColumn->restore();
            }

            return $existingColumn;
        }

        $registryType = $this->registryTypeFor((string) ($boardColumn['type'] ?? 'text'));
        $newColumn = CustomTableColumn::create([
            'table_key' => $tableKey,
            'name' => $title,
            'type' => $registryType,
            'settings' => $this->defaultSettings($registryType, $this->parseOptions((string) ($boardColumn['settings_str'] ?? ''), $registryType)),
            'position' => (int) CustomTableColumn::query()->where('table_key', $tableKey)->max('position') + 1,
            'created_by' => $createdBy,
        ]);

        $known->put($key, $newColumn);

        return $newColumn;
    }

    /**
     * All of the table's columns keyed by nameKey() — trashed rows included,
     * because the unique index counts them.
     *
     * @return Collection<string, CustomTableColumn>
     */
    private function columnsByName(string $tableKey): Collection
    {
        return CustomTableColumn::query()
            ->withTrashed()
            ->where('table_key', $tableKey)
            ->get()
            ->keyBy(fn (CustomTableColumn $column): string => $this->nameKey($column->name));
    }

    /**
     * Matching key for column-name lookups: the unique index on
     * (table_key, name) is case-insensitive (utf8mb4_*_ci, trailing-space
     * insensitive) — PHP's exact, case-sensitive keys miss matches that the
     * index rejects with a duplicate-key error.
     */
    private function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * Upsert the domain row for one monday item and write all mapped column
     * values. Returns true on success (or no mapped columns), false if any
     * mapped value failed validation.
     *
     * @param  array<string, mixed>  $item  one element from MondayApiClient::items()
     */
    public function mapItem(string $tableKey, array $item): bool
    {
        $itemId = (string) ($item['id'] ?? '');

        if ($itemId === '') {
            return false;
        }

        $setting = MondaySyncSetting::forDomain($tableKey);
        $fieldMap = $this->fieldMapFor($tableKey, $setting);

        if ($fieldMap === []) {
            return false;
        }

        $columns = CustomTableColumn::query()
            ->where('table_key', $tableKey)
            ->whereIn('id', array_values($fieldMap))
            ->get()
            ->keyBy('id');

        $dynamic = DynamicTable::query()->where('key', $tableKey)->first();
        $core = $dynamic ? null : MondayCoreTargets::resolve($tableKey);

        if (! $dynamic && ! $core) {
            return false;
        }

        if ($dynamic) {
            $row = DynamicRow::query()->updateOrCreate(
                [
                    'table_key' => $tableKey,
                    'source_system' => 'monday:'.$tableKey,
                    'source_record_id' => $itemId,
                ],
                [
                    'name' => Str::limit((string) ($item['name'] ?? $itemId), 255),
                    'source_updated_at' => ! empty($item['updated_at']) ? $item['updated_at'] : null,
                ],
            );
        } else {
            // Core table: a domain row with the item name in the title field
            // (setting override or the target's default).
            $titleField = MondayCoreTargets::titleField($tableKey, $setting->title_field);

            $values = [
                $titleField => Str::limit((string) ($item['name'] ?? $itemId), 255),
                'source_updated_at' => ! empty($item['updated_at']) ? $item['updated_at'] : null,
                'raw_data' => ['monday_item_id' => $itemId],
            ];

            // installations.account_id is NOT NULL — anchor monday-sourced
            // installations to a stable placeholder account per domain.
            if ($core['model'] === Installation::class) {
                $values['account_id'] = $this->installationAccountId($tableKey);
            }

            $row = $core['model']::query()->updateOrCreate(
                [
                    'source_system' => 'monday:'.$tableKey,
                    'source_record_id' => $itemId,
                ],
                $values,
            );
        }

        $rowId = $row->getKey();
        $ok = true;

        foreach ($item['column_values'] ?? [] as $value) {
            $columnId = $fieldMap[(string) ($value['id'] ?? '')] ?? null;

            if ($columnId === null) {
                continue;
            }

            $column = $columns->get((int) $columnId);

            if (! $column) {
                continue;
            }

            try {
                $raw = $this->normalizeValue((string) ($value['type'] ?? 'text'), $value);

                if ($raw === null || $raw === '') {
                    $this->writeValue($rowId, $column, []);

                    continue;
                }

                // Shared import boundary: board labels seed the column's
                // options before validate() (the flagged import bug class —
                // never validate status/dropdown cells without seeding).
                ImportOptionSeeder::seed($column, $raw);

                $validated = $this->registry->resolve($column->type)->validate($raw, $column->settings ?? []);

                $this->writeValue($rowId, $column, $validated);
            } catch (Throwable) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * The field map for a domain: the canonical setting map first, then the
     * legacy dynamic-table registry column (pre-field_map connections).
     *
     * @return array<string, int>
     */
    private function fieldMapFor(string $tableKey, MondaySyncSetting $setting): array
    {
        if (! empty($setting->field_map)) {
            return $setting->field_map;
        }

        $registryMap = DynamicTable::query()->where('key', $tableKey)->value('monday_field_map');

        return is_array($registryMap) ? $registryMap : [];
    }

    /**
     * A stable placeholder Account so monday-sourced installations (whose
     * account_id is NOT NULL) have a valid parent. Idempotent per domain.
     */
    private function installationAccountId(string $tableKey): int
    {
        return Account::query()->firstOrCreate(
            [
                'source_system' => 'monday',
                'source_record_id' => 'account-'.$tableKey,
            ],
            [
                'customer_name' => 'monday.com import',
            ],
        )->getKey();
    }

    /**
     * Reduce the raw monday column value (which carries the original text) to a
     * scalar the registry can validate. The typed fragments returned by
     * items() already carry 'text' and the type-specific label/number/date; we
     * pass the display string and let each column type's validate() coerce it.
     */
    private function normalizeValue(string $mondayType, array $value): mixed
    {
        $text = $value['text'] ?? null;

        if ($text === null || $text === '' || $text === '{}') {
            return null;
        }

        // Mirror values surface their resolved text in 'text' already.
        return $text;
    }

    private function writeValue(int $rowId, CustomTableColumn $column, array $validated): void
    {
        $shadow = $this->registry->resolve($column->type)->toShadowFields($validated);

        CustomTableColumnValue::query()->updateOrCreate(
            ['custom_column_id' => $column->id, 'row_id' => $rowId],
            [
                'value' => $validated,
                'value_text' => $shadow['value_text'] ?? null,
                'value_number' => $shadow['value_number'] ?? null,
                'value_date' => $shadow['value_date'] ?? null,
            ],
        );
    }

    /**
     * Parse monday's settings_str (a JSON string) into the registry's label
     * options for status/dropdown columns. Status labels are a map of index
     * => label ({"labels": {"0": "New"}}); dropdown labels are a list of
     * objects keyed by name ({"labels": [{"id": 1, "name": "AEONMED"}]}).
     * Blank labels (real status maps contain them) are dropped — an empty
     * option can never be picked or validated.
     *
     * @return array<int, array{index: int, label: string}>
     */
    private function parseOptions(string $settingsStr, string $registryType): array
    {
        if (! in_array($registryType, ['status', 'dropdown'], true) || $settingsStr === '') {
            return [];
        }

        $settings = json_decode($settingsStr, true);

        if (! is_array($settings)) {
            return [];
        }

        $labels = $settings['labels'] ?? $settings['options'] ?? [];

        if (! is_array($labels) || $labels === []) {
            return [];
        }

        $options = [];
        foreach ($labels as $index => $label) {
            if (is_array($label)) {
                $label = $label['label'] ?? $label['name'] ?? $label['title'] ?? '';
            }

            $label = (string) $label;

            if ($label === '') {
                continue;
            }

            $options[] = ['index' => (int) $index, 'label' => $label];
        }

        return $options;
    }

    private function defaultSettings(string $type, array $options = []): array
    {
        if (in_array($type, ['status', 'dropdown'], true) && $options !== []) {
            return [
                'multi' => $type === 'dropdown',
                'options' => $options,
            ];
        }

        return match ($type) {
            'number' => ['precision' => 2],
            'formula' => ['expression' => ''],
            default => [],
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    public function mapItems(string $tableKey, Collection $items): array
    {
        $imported = 0;
        $failed = 0;

        foreach ($items as $item) {
            $this->mapItem($tableKey, $item) ? $imported++ : $failed++;
        }

        return ['imported' => $imported, 'failed' => $failed];
    }
}
