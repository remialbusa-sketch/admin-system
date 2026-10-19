<?php

namespace App\Support;

use App\Models\CustomTableColumn;
use App\Models\MondaySyncSetting;
use App\Services\ColumnTypeRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** One canonical column across imported, synced, edited, exported and charted rows. */
class MergedColumnValue
{
    public static function bindings(string $tableKey, string $model): array
    {
        if ((MondayCoreTargets::resolve($tableKey)['model'] ?? null) !== $model) {
            return [];
        }
        $setting = MondaySyncSetting::where('domain', $tableKey)->first();
        $bindings = [];
        foreach (CustomTableColumn::where('table_key', $tableKey)->orderBy('position')->get() as $column) {
            $field = CoreColumnMapping::destination($tableKey, $column, $setting);
            if ($field === null && $tableKey === 'installed-products') {
                // Read aliases only. Import/sync still leave manual native
                // fields alone; their existing source cells remain intact.
                $field = match (mb_strtolower(preg_replace('/[^\pL\pN]+/u', ' ', trim($column->name)))) {
                    'pms frequency' => 'pms_frequency', 'tsp in charge' => 'tsp_in_charge', default => null,
                };
            }
            $compatible = $field !== null && (str_contains($field, '.')
                ? (in_array($column->type, ['text', 'long_text', 'status', 'dropdown'], true) || ($field === 'account.customer_address' && $column->type === 'location'))
                : MondayColumnCompatibility::core($field, $column->type));
            if ($compatible) {
                $bindings[$field][] = ['id' => $column->id, 'type' => $column->type,
                    'options' => $column->settings['options'] ?? []];
            }
        }

        return $bindings;
    }

    public static function value(mixed $native, array $column, Collection $cells): mixed
    {
        if ($native !== null && trim((string) $native) !== '') {
            return $native;
        }
        $values = [];
        foreach ($column['source_columns'] ?? [] as $source) {
            $cell = $cells->get($source['id']);
            if (! $cell?->value || ($column['type'] === 'date' && $source['type'] !== 'date')) {
                continue;
            }
            $text = trim(app(ColumnTypeRegistry::class)->resolve($source['type'])->toDisplayString($cell->value));
            if (in_array($column['key'] ?? '', ['tsp_name', 'tsp_assignment', 'tsp_in_charge', 'coordinator'], true)) {
                $text = PersonName::clean($text) ?? '';
            }
            if ($text !== '') {
                $values[$text] = $text;
            }
        }

        return count($values) === 1 ? array_values($values)[0] : $native;
    }

    public static function fieldSql(string $tableKey, string $model, string $field, string $type = 'text'): string
    {
        $table = (new $model)->getTable();

        return self::sql(['type' => $type, 'table_key' => $tableKey, 'canonical_key' => $field,
            'source_columns' => self::bindings($tableKey, $model)[$field] ?? []], $table.'.'.$field, $table.'.id');
    }

    /** Identifiers come only from server column definitions; values stay bound in callers. */
    public static function sql(array $column, string $native, string $rowId): string
    {
        $sources = $column['source_columns'] ?? [];
        if ($column['type'] === 'date') {
            $sources = array_filter($sources, fn ($source) => $source['type'] === 'date');
        }
        $ids = array_map(fn ($source) => (int) $source['id'], $sources);
        if ($ids === []) {
            return $native;
        }
        $shadow = match ($column['type']) {
            'date' => 'value_date', 'number' => 'value_number', default => 'value_text',
        };
        $candidate = "NULLIF(TRIM(CAST(merged_value.$shadow AS CHAR)), '')";
        $guard = '';
        if (in_array($column['canonical_key'] ?? $column['key'] ?? '', ['tsp_name', 'tsp_assignment', 'tsp_in_charge', 'coordinator'], true)) {
            foreach (['http://', 'https://', 'www.', 'data:', 'javascript:', '<', '>'] as $unsafe) {
                $guard .= " AND LOWER($candidate) NOT LIKE '%$unsafe%'";
            }
        }
        $fallback = '(SELECT CASE WHEN COUNT(DISTINCT '.$candidate.') = 1 THEN MAX('.$candidate.') END'
            .' FROM table_custom_column_values AS merged_value WHERE merged_value.row_id = '.$rowId
            .' AND merged_value.custom_column_id IN ('.implode(',', $ids).')'
            ." AND TRIM(CAST(merged_value.value AS CHAR)) NOT IN ('[]', '{}', 'null')".$guard.')';
        if (isset($column['table_key'], $column['canonical_key'])) {
            $quote = fn ($value) => DB::connection()->getPdo()->quote($value);
            $fallback = '(CASE WHEN NOT EXISTS (SELECT 1 FROM record_edit_logs AS merged_edit WHERE merged_edit.row_id = '.$rowId
                .' AND merged_edit.table_key = '.$quote($column['table_key']).' AND merged_edit.field = '.$quote($column['canonical_key'])
                ." AND merged_edit.action = 'updated' AND (merged_edit.new_value IS NULL OR merged_edit.new_value = '')) THEN ".$fallback.' END)';
        }

        return "COALESCE(NULLIF(TRIM(CAST($native AS CHAR)), ''), $fallback)";
    }
}
