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

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('The email value must be valid.');
        }

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
