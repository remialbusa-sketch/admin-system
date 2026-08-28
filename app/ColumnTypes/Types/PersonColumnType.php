<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class PersonColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'person';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $userIds = $input['user_ids'] ?? $raw;
        $userIds = is_array($userIds) ? $userIds : [$userIds];

        if ($userIds === [] || collect($userIds)->contains(fn (mixed $id): bool => ! is_numeric($id) || (int) $id < 1)) {
            $this->fail('The person value must contain one or more valid user IDs.');
        }

        return ['user_ids' => array_values(array_unique(array_map('intval', $userIds)))];
    }

    public function toShadowFields(array $value): array
    {
        $names = $value['user_names'] ?? [];
        $ids = $value['user_ids'] ?? [];

        return [
            'value_text' => $names !== []
                ? $this->displayList($names)
                : $this->displayList($ids),
        ];
    }

    public function toDisplayString(?array $value): string
    {
        return $this->displayList($value['user_names'] ?? $value['user_ids'] ?? []);
    }
}
