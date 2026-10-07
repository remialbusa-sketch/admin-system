<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class PersonColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'person';
    }

    /**
     * Person values come in two shapes:
     *   - numeric local user ids (grid picker, id-style Excel cells), and
     *   - display names (monday people cells carry names/emails; name Excel
     *     cells) — stored as user_names and rendered by toDisplayString().
     * The explicit user_ids key stays strictly numeric.
     */
    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);

        if (array_key_exists('user_names', $input)) {
            return ['user_names' => $this->cleanNames($input['user_names'])];
        }

        if (array_key_exists('user_ids', $input)) {
            $userIds = is_array($input['user_ids']) ? $input['user_ids'] : [$input['user_ids']];

            if ($userIds === [] || collect($userIds)->contains(fn (mixed $id): bool => ! is_numeric($id) || (int) $id < 1)) {
                $this->fail('The person value must contain one or more valid user IDs.');
            }

            return ['user_ids' => array_values(array_unique(array_map('intval', $userIds)))];
        }

        $values = $input !== [] ? $input : (is_array($raw) ? $raw : [$raw]);

        if ($values === []) {
            $this->fail('The person value must contain one or more valid user IDs.');
        }

        $numeric = array_filter($values, fn (mixed $value): bool => is_numeric($value));

        if (count($numeric) === count($values)) {
            if (collect($values)->contains(fn (mixed $id): bool => (int) $id < 1)) {
                $this->fail('The person value must contain one or more valid user IDs.');
            }

            return ['user_ids' => array_values(array_unique(array_map('intval', $values)))];
        }

        if ($numeric !== []) {
            $this->fail('The person value cannot mix user IDs and names.');
        }

        return ['user_names' => $this->cleanNames($values)];
    }

    /**
     * Non-empty unique display names from a scalar-or-list input.
     */
    private function cleanNames(mixed $names): array
    {
        $values = is_array($names) ? $names : [$names];
        $clean = [];

        foreach ($values as $name) {
            if (! is_scalar($name)) {
                continue;
            }

            $name = trim((string) $name);

            if ($name !== '') {
                $clean[] = $name;
            }
        }

        if ($clean === []) {
            $this->fail('The person value must contain one or more valid user IDs.');
        }

        return array_values(array_unique($clean));
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
