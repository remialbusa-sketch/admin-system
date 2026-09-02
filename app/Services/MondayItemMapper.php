<?php

namespace App\Services;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * M-DC: maps monday.com items into a user-created (dynamic) table.
 *
 * Two responsibilities:
 *   1. autoCreateColumns() — given the board's real columns (from
 *      monday:inspect-board), create missing table_custom_columns on the
 *      dynamic table and store the monday column id -> custom column id map
 *      (*the fast auto-map option the owner chose*).
 *   2. mapItem() — upsert a DynamicRow (source_system = monday, source
 *      record id = monday item id) and write each mapped column value through
 *      the registry, exactly like a manual cell write.
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
     * Auto-create missing columns on the table from the board's real columns
     * and persist the field map (monday column id -> custom column id).
     *
     * @param  array<int, array{id: string, title: string, type: string}>  $boardColumns
     */
    public function autoCreateColumns(DynamicTable $table, array $boardColumns): int
    {
        $existing = CustomTableColumn::query()
            ->where('table_key', $table->key)
            ->get()
            ->keyBy('name');

        $nextPosition = (int) CustomTableColumn::query()
            ->where('table_key', $table->key)
            ->max('position') + 1;

        $fieldMap = [];
        $created = 0;

        foreach ($boardColumns as $column) {
            $id = (string) ($column['id'] ?? '');
            $title = trim((string) ($column['title'] ?? ''));
            $type = (string) ($column['type'] ?? 'text');

            if ($id === '' || $title === '') {
                continue;
            }

            // If a column with the same title already exists, map onto it rather
            // than duplicating.
            if ($existing->has($title)) {
                $fieldMap[$id] = $existing->get($title)->id;

                continue;
            }

            $registryType = $this->registryTypeFor($type);
            $newColumn = CustomTableColumn::create([
                'table_key' => $table->key,
                'name' => $title,
                'type' => $registryType,
                'settings' => $this->defaultSettings($registryType, $this->parseOptions((string) ($column['settings_str'] ?? ''), $registryType)),
                'position' => $nextPosition++,
                'created_by' => $table->created_by,
            ]);

            $existing->put($title, $newColumn);
            $fieldMap[$id] = $newColumn->id;
            $created++;
        }

        // Merge with any existing map entries (the owner may have mapped extra
        // columns by hand); board columns win on collision.
        $table->update([
            'monday_field_map' => array_merge($table->monday_field_map ?? [], $fieldMap),
        ]);

        return $created;
    }

    /**
     * Upsert a DynamicRow for one monday item and write all mapped column
     * values. Returns true on success (or no mapped columns), false if any
     * mapped value failed validation.
     *
     * @param  array<string, mixed>  $item  one element from MondayApiClient::items()
     */
    public function mapItem(DynamicTable $table, array $item): bool
    {
        $itemId = (string) ($item['id'] ?? '');
        $fieldMap = $table->monday_field_map ?? [];

        if ($itemId === '' || $fieldMap === []) {
            return false;
        }

        $columns = CustomTableColumn::query()
            ->where('table_key', $table->key)
            ->whereIn('id', array_values($fieldMap))
            ->get()
            ->keyBy('id');

        $row = DynamicRow::query()->updateOrCreate(
            [
                'table_key' => $table->key,
                'source_system' => 'monday:'.$table->key,
                'source_record_id' => $itemId,
            ],
            [
                'name' => Str::limit((string) ($item['name'] ?? $itemId), 255),
                'source_updated_at' => ! empty($item['updated_at']) ? $item['updated_at'] : null,
            ],
        );

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
                $validated = ($raw === null || $raw === '')
                    ? []
                    : $this->registry->resolve($column->type)->validate($raw, $column->settings ?? []);

                $this->writeValue($row, $column, $validated);
            } catch (Throwable) {
                $ok = false;
            }
        }

        return $ok;
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

    private function writeValue(DynamicRow $row, CustomTableColumn $column, array $validated): void
    {
        $shadow = $this->registry->resolve($column->type)->toShadowFields($validated);

        CustomTableColumnValue::query()->updateOrCreate(
            ['custom_column_id' => $column->id, 'row_id' => $row->getKey()],
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
     * options for status/dropdown columns. monday encodes status labels as
     * {"labels": {"<index>": "<label>", ...}}; dropdowns use the same shape.
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
                $label = $label['label'] ?? '';
            }
            $options[] = ['index' => (int) $index, 'label' => (string) $label];
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
    public function mapItems(DynamicTable $table, Collection $items): array
    {
        $imported = 0;
        $failed = 0;

        foreach ($items as $item) {
            $this->mapItem($table, $item) ? $imported++ : $failed++;
        }

        return ['imported' => $imported, 'failed' => $failed];
    }
}
