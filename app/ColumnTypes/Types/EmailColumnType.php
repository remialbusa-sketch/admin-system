<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class EmailColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'email';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $email = $this->requiredString($input['email'] ?? (is_scalar($raw) ? $raw : null), 'email');

        // The file wins: cells like "a@x - b@y" (two addresses) or a stray
        // header label import verbatim instead of failing the whole row
        // (2026-09-30 decision). Only empty values fail, via requiredString.
        return ['email' => $email];
    }

    public function toShadowFields(array $value): array
    {
        return ['value_text' => $value['email'] ?? null];
    }

    public function toDisplayString(?array $value): string
    {
        return $this->displayScalar($value['email'] ?? null);
    }
}
