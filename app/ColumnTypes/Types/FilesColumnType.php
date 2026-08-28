<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class FilesColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'files';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $fileIds = $input['file_ids'] ?? $raw;
        $fileIds = is_array($fileIds) ? $fileIds : [$fileIds];

        if (collect($fileIds)->contains(fn (mixed $id): bool => ! is_numeric($id) || (int) $id < 1)) {
            $this->fail('The files value must contain valid file IDs.');
        }

        return ['file_ids' => array_values(array_unique(array_map('intval', $fileIds)))];
    }

    public function toShadowFields(array $value): array
    {
        return [];
    }

    public function toDisplayString(?array $value): string
    {
        $count = count($value['file_ids'] ?? []);

        return $count === 0 ? '' : $count.' file'.($count === 1 ? '' : 's');
    }
}
