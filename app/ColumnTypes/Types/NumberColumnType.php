<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class NumberColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'number';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $number = $input['number'] ?? $raw;

        if (is_bool($number) || $number === '' || ! is_numeric($number)) {
            $this->fail('The number value must be numeric.');
        }

        $precision = max(0, (int) ($settings['precision'] ?? 4));

        return ['number' => round((float) $number, $precision)];
    }

    public function toShadowFields(array $value): array
    {
        return ['value_number' => $value['number'] ?? null];
    }

    public function toDisplayString(?array $value): string
    {
        return $this->displayScalar($value['number'] ?? null);
    }
}
