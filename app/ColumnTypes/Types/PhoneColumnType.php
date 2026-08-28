<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class PhoneColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'phone';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $phone = $this->requiredString($input['phone'] ?? (is_scalar($raw) ? $raw : null), 'phone');

        return ['phone' => $phone];
    }

    public function toShadowFields(array $value): array
    {
        return ['value_text' => $value['phone'] ?? null];
    }

    public function toDisplayString(?array $value): string
    {
        return $this->displayScalar($value['phone'] ?? null);
    }
}
