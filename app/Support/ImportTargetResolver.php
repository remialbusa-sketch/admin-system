<?php

namespace App\Support;

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
            return $managed + ['dynamic' => false];
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
}
