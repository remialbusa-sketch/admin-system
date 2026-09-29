<?php

namespace Tests\Unit;

use App\Services\ImportMappingService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportPhantomColumnsTest extends TestCase
{
    /**
     * The live 503/500 root cause: system-exported workbooks carry stray
     * cells far to the right (up to XFD), stretching the declared dimension
     * across all 16,384 columns. Unbounded readers then instantiated
     * millions of cells — a fatal at Coordinate.php under the production
     * memory cap. The column-bounded filter must keep analysis flat.
     */
    public function test_analysis_survives_phantom_wide_dimensions_under_a_production_memory_cap(): void
    {
        // Restore the environment's own limit afterwards — never a hardcoded
        // one (see the note on memory_limit below).
        $memoryLimit = ini_get('memory_limit');

        ini_set('memory_limit', '512M');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PDB Data');

        $headers = ['Customer Name', 'Device Description', 'Brand', 'Machine Type', 'Serial Number', 'Region', 'Device Status', 'Installation Date'];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $header);
        }

        for ($row = 2; $row <= 2500; $row++) {
            foreach ($headers as $index => $header) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$row, $header.' '.$row);
            }
        }

        // Stray junk cells in the far right — stretches the declared
        // dimension to XFD exactly like real system exports do.
        $sheet->setCellValue('XFD1', 'junk');
        $sheet->setCellValue('XFB2', '#N/A');
        $sheet->setCellValue('XFC3', 'junk');

        $path = storage_path('app/import-phantom-columns-test.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $analysis = app(ImportMappingService::class)->analyze($path, 'installed-products');

            $this->assertSame('PDB Data', $analysis['preview']['sheet']);
            $this->assertSame(1, $analysis['preview']['headerRow']);

            $first = $analysis['preview']['columns'][0] ?? null;
            $this->assertNotNull($first);
            $this->assertSame('A', $first['letter']);
            $this->assertSame('Customer Name', $first['label']);
        } finally {
            @unlink($path);
            ini_set('memory_limit', $memoryLimit);
        }

        // memory_limit is process-wide: a test that lowers it and restores a
        // hardcoded value instead of the one it found starves every test that
        // runs after it, and the whole suite dies with "Premature end of PHP
        // process" on whichever test allocates enough to cross the cap.
        $this->assertSame(
            $memoryLimit,
            ini_get('memory_limit'),
            'This test must put memory_limit back exactly as it found it.',
        );
    }
}