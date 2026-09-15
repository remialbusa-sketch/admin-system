<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ChunkReadFilter implements IReadFilter
{
    /**
     * Sane column cap. Real business tables live far below this, but
     * system-exported workbooks routinely carry phantom formatting across
     * ALL 16,384 columns (up to XFD) — without a bound, every chunk loaded
     * millions of cells and blew past the memory limit (the live 503/500
     * import failures).
     */
    public const MAX_COLUMNS = 256;

    private readonly int $maxColumnIndex;

    public function __construct(
        private readonly int $startRow,
        private readonly int $endRow,
        int $maxColumns = self::MAX_COLUMNS,
    ) {
        $this->maxColumnIndex = max(1, min(16384, $maxColumns));
    }

    public function readCell($column, $row, $worksheetName = ''): bool
    {
        if ($row === 1 || ($row >= $this->startRow && $row <= $this->endRow)) {
            return Coordinate::columnIndexFromString($column) <= $this->maxColumnIndex;
        }

        return false;
    }
}
