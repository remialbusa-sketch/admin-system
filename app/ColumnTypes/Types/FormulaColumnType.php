<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class FormulaColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'formula';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        if (trim((string) ($settings['expression'] ?? '')) === '') {
            $this->fail('A formula column requires an expression in its settings.');
        }

        $input = $this->valueArray($raw);
        $number = $input['number'] ?? $input['result'] ?? $raw;

        if (is_bool($number) || $number === '' || ! is_numeric($number)) {
            $this->fail('The formula value must be a numeric computed result.');
        }

        return [
            'number' => (float) $number,
            'expression' => $settings['expression'],
        ];
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
