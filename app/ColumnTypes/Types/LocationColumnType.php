<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;

class LocationColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'location';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);

        if ($input === [] && is_scalar($raw)) {
            return ['address' => $this->requiredString($raw, 'address')];
        }

        $hasCoordinates = array_key_exists('lat', $input) || array_key_exists('lng', $input);

        if ($hasCoordinates && (! is_numeric($input['lat'] ?? null) || ! is_numeric($input['lng'] ?? null))) {
            $this->fail('Location latitude and longitude must be numeric together.');
        }

        if (! $hasCoordinates && ! isset($input['address'])) {
            $this->fail('A location needs an address or coordinates.');
        }

        $value = [];

        if ($hasCoordinates) {
            $value['lat'] = (float) $input['lat'];
            $value['lng'] = (float) $input['lng'];
        }

        if (isset($input['address'])) {
            $value['address'] = $this->requiredString($input['address'], 'address');
        }

        return $value;
    }

    public function toShadowFields(array $value): array
    {
        return ['value_text' => $value['address'] ?? null];
    }

    public function toDisplayString(?array $value): string
    {
        if (isset($value['address'])) {
            return $value['address'];
        }

        if (isset($value['lat'], $value['lng'])) {
            return $value['lat'].', '.$value['lng'];
        }

        return '';
    }
}
