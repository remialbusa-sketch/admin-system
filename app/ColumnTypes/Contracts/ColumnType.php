<?php

namespace App\ColumnTypes\Contracts;

interface ColumnType
{
    public function key(): string;

    /**
     * @return array<string, mixed>
     */
    public function validate(mixed $raw, array $settings = []): array;

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function toShadowFields(array $value): array;

    /**
     * @param  array<string, mixed>|null  $value
     */
    public function toDisplayString(?array $value): string;
}
