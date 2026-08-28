<?php

namespace App\ColumnTypes;

use App\ColumnTypes\Contracts\ColumnType;
use InvalidArgumentException;

abstract class AbstractColumnType implements ColumnType
{
    protected function fail(string $message): never
    {
        throw new InvalidArgumentException($message);
    }

    /**
     * @return array<string, mixed>
     */
    protected function valueArray(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    protected function requiredString(mixed $value, string $field = 'value'): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            $this->fail("The {$field} value must be a string.");
        }

        $value = trim((string) $value);

        if ($value === '') {
            $this->fail("The {$field} value is required.");
        }

        return $value;
    }

    protected function displayScalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<int, mixed>  $values
     */
    protected function displayList(array $values): string
    {
        return implode(', ', array_map(fn (mixed $value): string => $this->displayScalar($value), $values));
    }
}
