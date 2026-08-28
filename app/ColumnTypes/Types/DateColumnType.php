<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;
use Carbon\Carbon;

class DateColumnType extends AbstractColumnType
{
    public function key(): string
    {
        return 'date';
    }

    public function validate(mixed $raw, array $settings = []): array
    {
        $input = $this->valueArray($raw);
        $dateValue = $input['date'] ?? (is_scalar($raw) ? $raw : null);

        try {
            $date = Carbon::parse($this->requiredString($dateValue, 'date'));
        } catch (\Throwable) {
            $this->fail('The date value must be a valid date.');
        }

        $value = ['date' => $date->toDateString()];

        if (($settings['include_time'] ?? false) === true) {
            $value['time'] = isset($input['time'])
                ? Carbon::parse($input['time'])->format('H:i:s')
                : $date->format('H:i:s');
        }

        return $value;
    }

    public function toShadowFields(array $value): array
    {
        return ['value_date' => $value['date'] ?? null];
    }

    public function toDisplayString(?array $value): string
    {
        if (! isset($value['date'])) {
            return '';
        }

        return isset($value['time']) ? $value['date'].' '.$value['time'] : $value['date'];
    }
}
