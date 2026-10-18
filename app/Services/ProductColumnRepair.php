<?php

namespace App\Services;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\Installation;
use App\Models\MondaySyncSetting;
use App\Support\CoreColumnMapping;
use App\Support\MondayColumnCompatibility;
use App\Support\MondayCoreTargets;
use Illuminate\Support\Collection;

class ProductColumnRepair
{
    public function __construct(private ColumnTypeRegistry $registry) {}

    /** Plan from stored typed values; never fetch a board or change a row here. */
    public function plan(Installation $row, Collection $columns, Collection $cells, ?MondaySyncSetting $setting): array
    {
        $candidates = [];
        $invalid = 0;
        $reference = null;
        foreach ($columns as $column) {
            $value = $cells->get($column->id)?->value;
            if (! $value) {
                continue;
            }
            $text = trim($this->registry->resolve($column->type)->toDisplayString($value));
            if ($text === '') {
                continue;
            }
            if (mb_strtolower(trim($column->name)) === 'connection') {
                $reference = $text;
            }
            $field = CoreColumnMapping::destination('installed-products', $column, $setting);
            if ($field === null || ! isset(MondayCoreTargets::fieldOptions('installed-products')[$field])
                || ! MondayColumnCompatibility::core($field, $column->type)) {
                continue;
            }
            if (str_ends_with($field, '_date')) {
                try {
                    $text = $this->registry->resolve('date')->validate($text)['date'] ?? '';
                } catch (\InvalidArgumentException) {
                    $text = '';
                }
                if ($text === '') {
                    $invalid++;

                    continue;
                }
            }
            $candidates[$field][$text] = $text;
        }

        $changes = [];
        $conflicts = 0;
        foreach ($candidates as $field => $values) {
            if (count($values) !== 1) {
                $conflicts++;

                continue;
            }
            $text = array_values($values)[0];
            $current = $row->getAttribute($field);
            $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : trim((string) $current);
            if ($current === $text) {
                continue;
            }
            $oldTitle = $field === 'device_description' && $row->source_system === 'monday:installed-products'
                && preg_match('/^SN-\d+$/', $current) && $current === $reference;
            if ($current === '' || $oldTitle) {
                $changes[$field] = $text;
            } else {
                $conflicts++;
            }
        }

        return compact('changes', 'conflicts', 'invalid');
    }

    public function columns(): Collection
    {
        return CustomTableColumn::where('table_key', 'installed-products')->get();
    }

    public function cells(Collection $rows, Collection $columns): Collection
    {
        return CustomTableColumnValue::whereIn('custom_column_id', $columns->pluck('id'))
            ->whereIn('row_id', $rows->pluck('id'))->get()->groupBy('row_id')
            ->map(fn ($values) => $values->keyBy('custom_column_id'));
    }
}
