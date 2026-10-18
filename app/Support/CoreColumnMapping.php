<?php

namespace App\Support;

use App\Models\CustomTableColumn;
use App\Models\MondaySyncSetting;

/** Identify stored source columns feeding a canonical field, independently of labels. */
class CoreColumnMapping
{
    public static function destination(string $tableKey, CustomTableColumn $column, ?MondaySyncSetting $setting): ?string
    {
        $sourceIds = array_keys(array_filter($setting?->field_map ?? [], fn ($id) => (int) $id === $column->id));
        if ($sourceIds !== [] && $setting?->core_field_map !== null) {
            foreach ($sourceIds as $sourceId) {
                if (isset($setting->core_field_map[$sourceId])) {
                    $field = $setting->core_field_map[$sourceId];

                    return isset(MondayCoreTargets::fieldOptions($tableKey)[$field]) ? $field : null;
                }
            }

            // Account identity is mapped separately from installation fields.
            return self::accountField($tableKey, $column->name);
        }
        foreach (MondayCoreTargets::resolve($tableKey)['columns'] ?? [] as $label => $field) {
            if (self::normalize($label) === self::normalize($column->name)) {
                return $field;
            }
        }
        if ($tableKey === 'installed-products') {
            return match (self::normalize($column->name)) {
                'device description' => 'device_description',
                'equipment type', 'equipment type system' => 'equipment_type',
                'pulled out date', 'uninstalled date' => 'uninstallation_date',
                default => self::accountField($tableKey, $column->name),
            };
        }

        return null;
    }

    private static function accountField(string $tableKey, string $label): ?string
    {
        return $tableKey !== 'installed-products' ? null : match (self::normalize($label)) {
            'customer name' => 'account.customer_name',
            'address', 'customer address' => 'account.customer_address',
            'branch' => 'account.branch',
            default => null,
        };
    }

    private static function normalize(string $value): string
    {
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value)) ?? '');
    }
}
