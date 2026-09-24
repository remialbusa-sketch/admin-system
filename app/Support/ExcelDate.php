<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/**
 * Excel serial-date conversion for import paths.
 *
 * The import readers run with setReadDataOnly(true), so number formats are
 * dropped and date cells surface as raw serials — "45853" or, with a time
 * component, "45853.60416…". Feeding those to Carbon::parse() either throws
 * (integer serial) or silently resolves to 1970-01-01 (fractional serial
 * read as a Unix timestamp). Every consumer that turns a raw cell into a
 * date must go through toCarbon() instead.
 */
class ExcelDate
{
    /**
     * Serials must be strictly above 20000 (1954-08-18) and at most 2958465
     * (Excel's own ceiling, 9999-12-31). Outside that window a number is an
     * ID, a quantity or a compact value like "20250101" — never a date — so
     * it falls through to normal parsing instead of becoming a wrong century.
     */
    private const MIN_SERIAL = 20000;

    private const MAX_SERIAL = 2958465;

    /**
     * Parse a raw import cell (or display value) into a Carbon date, or null
     * when it is empty or not a date at all. Handles Excel serials ("45853",
     * "45853.60416…") including the managed-import date + time pair
     * ("45853 0.60416…", each half numeric), plus everything Carbon can
     * read ("2025-07-15", "07/15/25", …).
     */
    public static function toCarbon(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // Date column + separate time column, each already a serial: sum to
        // one serial (matches the legacy managed-import dateTime behavior).
        $parts = preg_split('/\s+/', $value);
        if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            $serial = (float) $parts[0] + (float) $parts[1];

            if ($serial > self::MIN_SERIAL && $serial <= self::MAX_SERIAL) {
                return self::fromSerial($serial);
            }
        }

        if (is_numeric($value)) {
            $serial = (float) $value;

            if ($serial > self::MIN_SERIAL && $serial <= self::MAX_SERIAL) {
                return self::fromSerial($serial);
            }
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function fromSerial(float $serial): Carbon
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);

        return Carbon::create(1899, 12, 30)->addDays($days)->addSeconds($seconds);
    }
}
