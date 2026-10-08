<?php

namespace App\Support;

use App\Models\CustomTableColumn;
use App\Models\DynamicTable;
use App\Services\ImportMappingService;

/**
 * Resolves mappable import targets for any table key — managed tables
 * (ImportMappingService::TARGETS) or user-created dynamic tables (their
 * custom columns). This is the single source of truth for the import
 * wizard's field catalog, so the classic page, auto-mapping and validation
 * can never drift apart.
 *
 * Shape:
 *   [
 *     'label'   => string,
 *     'sheet'   => ?string,          // preferred sheet for managed tables
 *     'dynamic' => bool,
 *     'fields'  => [ ['key','label','required','kind'], ... ],
 *   ]
 */
class ImportTargetResolver
{
    public static function for(string $tableKey): ?array
    {
        $managed = ImportMappingService::TARGETS[$tableKey] ?? null;

        if ($managed !== null) {
            // Custom columns previously added to this core table are import
            // targets too: a file column connecting to one overwrites it
            // (name-based matching) instead of duplicating it. A custom whose
            // label duplicates a managed field's label is a shadow twin
            // (monday sync auto-creates board columns like "Customer Name"
            // beside core customer_name): the identical dropdown labels route
            // workbook headers into shadow cells and poison recall, so the
            // managed field stays the only target for that label.
            $managedLabels = [];
            foreach ($managed['fields'] as $field) {
                $managedLabels[self::normalizeLabel((string) $field['label'])] = true;
            }

            $custom = CustomTableColumn::query()
                ->where('table_key', $tableKey)
                ->orderBy('position')
                ->get(['id', 'name', 'type'])
                ->reject(fn (CustomTableColumn $column): bool => isset($managedLabels[self::normalizeLabel($column->name)]))
                ->map(fn (CustomTableColumn $column): array => [
                    'key' => $column->columnKey(),
                    'label' => $column->name,
                    'required' => false,
                    'kind' => $column->type,
                ])
                ->all();

            return array_merge($managed, [
                'dynamic' => false,
                'fields' => [...$managed['fields'], ...$custom],
            ]);
        }

        $dynamic = DynamicTable::query()->where('key', $tableKey)->first();

        if ($dynamic === null) {
            return null;
        }

        $fields = $dynamic->columns()
            ->get(['id', 'name', 'type'])
            ->map(fn ($column): array => [
                'key' => $column->columnKey(),
                'label' => $column->name,
                'required' => false,
                'kind' => $column->type,
            ])
            ->all();

        return [
            'label' => $dynamic->name,
            'sheet' => null,
            'dynamic' => true,
            'fields' => [
                ['key' => '__identity__', 'label' => 'Identity (Item ID)', 'required' => false, 'kind' => 'text'],
                ['key' => 'name', 'label' => 'Name', 'required' => false, 'kind' => 'text'],
                ...$fields,
            ],
        ];
    }

    public static function exists(string $tableKey): bool
    {
        return self::for($tableKey) !== null;
    }

    /**
     * Label normalization mirroring ImportMappingService::normalizeLabel:
     * "BU No." and "bu no" are the same target label.
     */
    private static function normalizeLabel(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/[^a-z0-9]+/', ' ', $label) ?? '';

        return trim(preg_replace('/\s+/', ' ', $label) ?? '');
    }
}
