<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class DropdownColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'dropdown';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $selected = $input['selected'] ?? $raw;
        $selected = is_array($selected) ? $selected : [$selected];
        $options = $settings['options'] ?? [];
        $indexes = [];
        $labels = [];

        foreach ($selected as $candidate) {
            $option = null;

            if (is_numeric($candidate) && (string) (int) $candidate === (string) $candidate) {
                $option = collect($options)->firstWhere('index', (int) $candidate);
            } elseif (is_string($candidate)) {
                $option = collect($options)->firstWhere('label', $candidate);
            }

            if ($option === null) {
                $this->fail('The dropdown value is not one of the configured options.');
            }

            $indexes[] = (int) $option['index'];
            $labels[] = (string) $option['label'];
        }

        $indexes = array_values(array_unique($indexes));
        $labels = array_values(array_unique($labels));

        if (($settings['multi'] ?? false) !== true && count($indexes) > 1) {
            $this->fail('This dropdown accepts only one option.');
        }

        return [
            'selected' => $indexes,
            'labels' => $labels,
        ];
    }

    public function toShadowFields(array $value): array
    {
        return ['value_text' => isset($value['labels']) ? implode(', ', $value['labels']) : null];
    }

    public function toDisplayString(?array $value): string
    {
        return isset($value['labels']) ? implode(', ', $value['labels']) : $this->displayList($value['selected'] ?? []);
    }
}
