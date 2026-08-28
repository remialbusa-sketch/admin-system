<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class StatusColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'status';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $candidate = $input['index'] ?? $input['label'] ?? $raw;
        $options = $settings['options'] ?? [];
        $index = null;

        if (is_numeric($candidate) && (string) (int) $candidate === (string) $candidate) {
            $index = (int) $candidate;
        } elseif (is_string($candidate)) {
            foreach ($options as $option) {
                if (($option['label'] ?? null) === $candidate) {
                    $index = (int) ($option['index'] ?? 0);
                    break;
                }
            }
        }

        if ($index === null || ($options !== [] && ! in_array($index, array_map(
            fn (array $option): int => (int) ($option['index'] ?? 0),
            $options,
        ), true))) {
            $this->fail('The status value is not one of the configured options.');
        }

        $label = collect($options)->firstWhere('index', $index)['label'] ?? null;

        return array_filter([
            'index' => $index,
            'label' => $label,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function toShadowFields(array $value): array
    {
        $index = (int) ($value['index'] ?? 0);

        return [
            'value_text' => $value['label'] ?? (string) $index,
            'value_number' => $index,
        ];
    }

    public function toDisplayString(?array $value): string
    {
        return $this->displayScalar($value['label'] ?? $value['index'] ?? null);
    }
}
