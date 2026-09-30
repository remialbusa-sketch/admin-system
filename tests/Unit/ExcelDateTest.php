<?php

namespace Tests\Unit;

use App\Support\ExcelDate;
use Tests\TestCase;

/**
 * ExcelDate is the single shared date path for import cells (readDataOnly
 * drops number formats, so dates arrive as raw serials). Garbage numerics
 * must never reach Carbon::parse: it reads a bare number as a Unix
 * timestamp, turning junk cells like -691914.37 into 1969-12-23 dates that
 * either land wrong or kill the row when a TIMESTAMP column rejects them
 * (2026-09-30 incident, 3 rows).
 */
class ExcelDateTest extends TestCase
{
    public function test_negative_garbage_numerics_never_become_dates(): void
    {
        $this->assertNull(ExcelDate::toCarbon('-691914.37380787'));
        $this->assertNull(ExcelDate::toCarbon('-692991.24880787'));
        $this->assertNull(ExcelDate::toCarbon('-692239.3321412'));
    }

    public function test_out_of_window_plain_numbers_are_not_dates(): void
    {
        // An ID/quantity in a date cell: not a serial, not a timestamp.
        $this->assertNull(ExcelDate::toCarbon('12345'));
        $this->assertNull(ExcelDate::toCarbon('202501010'));
    }

    public function test_compact_yyyymmdd_values_still_parse_as_dates(): void
    {
        $compact = ExcelDate::toCarbon('20250101');
        $this->assertNotNull($compact);
        $this->assertSame('2025-01-01', $compact->toDateString());
    }

    public function test_serials_in_the_valid_window_still_convert(): void
    {
        $date = ExcelDate::toCarbon('45853');
        $this->assertNotNull($date);
        $this->assertSame('2025-07-15', $date->toDateString());

        // Serial with a time fraction keeps the time-of-day.
        $withTime = ExcelDate::toCarbon('45853.604166666666');
        $this->assertNotNull($withTime);
        $this->assertSame('2025-07-15', $withTime->toDateString());
        $this->assertSame('14:30:00', $withTime->format('H:i:s'));
    }

    public function test_empty_values_stay_null(): void
    {
        $this->assertNull(ExcelDate::toCarbon(null));
        $this->assertNull(ExcelDate::toCarbon(''));
        $this->assertNull(ExcelDate::toCarbon('   '));
    }
}
