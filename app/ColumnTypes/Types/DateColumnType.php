<?php

namespace App\ColumnTypes\Types;

use App\ColumnTypes\AbstractColumnType;
use App\Support\ExcelDate;
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
            // ExcelDate, not Carbon::parse: import cells arrive as Excel
            // serials ("45853" / "45853.60416…") and Carbon either rejects
            // them or silently reads a fractional serial as 1970-01-01.
            $date = ExcelDate::toCarbon($this->requiredString($dateValue, 'date'));
        } catch (\Throwable) {
            $this->fail('The date value must be a valid date.');
        }

        if ($date === null) {
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
