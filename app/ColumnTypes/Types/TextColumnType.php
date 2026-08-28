<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class TextColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'text';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $text = $this->requiredString($input['text'] ?? (is_scalar($raw) ? $raw : null));

        if (isset($settings['max_length']) && strlen($text) > (int) $settings['max_length']) {
            $this->fail('The text value exceeds the configured maximum length.');
        }

        return ['text' => $text];
    }

    public function toShadowFields(array $value): array
    {
        return ['value_text' => $value['text'] ?? null];
    }

    public function toDisplayString(?array $value): string
    {
        return $this->displayScalar($value['text'] ?? null);
    }
}
