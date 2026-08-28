<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class LongTextColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'long_text';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $text = $this->requiredString($input['text'] ?? (is_scalar($raw) ? $raw : null));

        return ['text' => $text];
    }

    public function toShadowFields(array $value): array
    {
        return ['value_text' => isset($value['text']) ? mb_substr($value['text'], 0, 255) : null];
    }

    public function toDisplayString(?array $value): string
    {
        return $this->displayScalar($value['text'] ?? null);
    }
}
