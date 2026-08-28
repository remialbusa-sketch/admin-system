<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class CheckboxColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'checkbox';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $checked = $input['checked'] ?? $raw;

        if (is_string($checked)) {
            $checked = match (strtolower($checked)) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => null,
            };
        } elseif (is_numeric($checked)) {
            $checked = (bool) $checked;
        }

        if (! is_bool($checked)) {
            $this->fail('The checkbox value must be boolean.');
        }

        return ['checked' => $checked];
    }

    public function toShadowFields(array $value): array
    {
        return ['value_number' => ($value['checked'] ?? false) ? 1 : 0];
    }

    public function toDisplayString(?array $value): string
    {
        return ($value['checked'] ?? false) ? 'Yes' : 'No';
    }
}
