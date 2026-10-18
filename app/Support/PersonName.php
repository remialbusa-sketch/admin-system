<?php

namespace App\Support;

/** Person fields can contain names, emails, or source IDs, never attachments. */
class PersonName
{
    public static function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $name = trim((string) $value);
        if ($name === '' || preg_match('~(?:https?://|www\.|(?:data|javascript):|[<>])~i', $name)) {
            return null;
        }

        return $name;
    }

    public static function technician(mixed $id, mixed $display): array
    {
        $id = self::clean($id);
        $display = self::clean($display);

        return ['tsp_name' => $id ?? $display, 'tsp_display_name' => $display ?? $id];
    }
}
