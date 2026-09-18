<?php

namespace Tests\Unit;

use App\Services\ImportMappingService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportFormulaCalculationTest extends TestCase
{
    /**
     * The persistent live 500 root cause: scanSheet() called toArray() with
     * calculateFormulas=true, so PhpSpreadsheet re-evaluated every formula in
     * the scanned window. Google-Sheets exports carry formulas like
     * =IFERROR(__xludf.DUMMYFUNCTION("QUERY(PDB!B8:AI14342, ...")...) — a
     * BOUNDED range reference makes the calculation engine enumerate every
     * cell reference in it (~500k refs / ~33 MB in one array), an
     * uncatchable OOM under a locked memory_limit that surfaced as a bare
     * 500 on POST /livewire/update. Analysis must read cached values only.
     *
     * Note: whole-column ranges (SUM(A:A)) are handled without enumeration,
     * so the regression fixture must use a bounded range like the real file.
     */
    public function test_preview_reads_cached_values_without_calculating_wide_formula_ranges(): void
    {
        ini_set('memory_limit', '128M');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PDB Data');
        $sheet->setCellValue('A1', 'Customer Name');
        $sheet->setCellValue('B1', 'Device Description');
        $sheet->setCellValue('A2', 'Hospital A');
        $sheet->setCellValue('B2', 'Machine X');

        // A bounded-range formula inside the scanned window (rows 1-30),
        // mirroring the real QUERY export. Recalculating it enumerates
        // 35 x 9,999 = ~350k cell references (~86 MB measured).
        $sheet->setCellValue('B28', '=SUM(B1:AI9999)');

        $path = storage_path('app/import-formula-memory-test.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        try {
            $analysis = app(ImportMappingService::class)->analyze($path, 'installed-products');

            $this->assertSame('PDB Data', $analysis['preview']['sheet']);
            $this->assertSame('A', $analysis['preview']['columns'][0]['letter']);
            $this->assertSame('Customer Name', $analysis['preview']['columns'][0]['label']);

            $peakDelta = memory_get_peak_usage(true) - $before;

            $this->assertLessThan(
                32 * 1024 * 1024,
                $peakDelta,
                'Analysis recalculated formulas — PhpSpreadsheet enumerated the bounded range (~86 MB allocation).',
            );
        } finally {
            @unlink($path);
            ini_set('memory_limit', '128M');
        }
    }
}
