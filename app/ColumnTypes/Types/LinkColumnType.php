<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class LinkColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'link';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $url = $this->requiredString($input['url'] ?? (is_scalar($raw) ? $raw : null), 'url');

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->fail('The link value must be a valid URL.');
        }

        return [
            'url' => $url,
            'text' => isset($input['text']) ? $this->requiredString($input['text'], 'link text') : $url,
        ];
    }

    public function toShadowFields(array $value): array
    {
        return ['value_text' => $value['text'] ?? $value['url'] ?? null];
    }

    public function toDisplayString(?array $value): string
    {
        return $this->displayScalar($value['text'] ?? $value['url'] ?? null);
    }
}
