<?php

namespace Tests\Feature;

use App\Models\TechnicalReport;
use App\Services\SourceWorkbookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Technical Reports row identity must come from the file position, never from
 * the mapped Reference Number value: files legitimately repeat reference
 * numbers (the 2026-10-01 batch lost 286 rows to key collisions because 182
 * Name values repeated), and replace-all imports purge first, so a stable
 * physical row id keeps every file row while staying idempotent on replay.
 */
class ImportRowIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function workbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tr-identity-').'.csv';
        file_put_contents($path, implode(PHP_EOL, [
            'Reference Number,Name',
            'SN-00001,SROne',
            'SN-00001,SRTwo',
            'SN-00002,SRThree',
        ]));

        return $path;
    }

    public function test_duplicate_reference_numbers_keep_every_file_row(): void
    {
        $path = $this->workbook();

        try {
            app(SourceWorkbookImportService::class)->importTechnicalReports($path);

            $this->assertSame(3, TechnicalReport::query()->count());
            $this->assertSame(2, TechnicalReport::query()->where('reference_number', 'SN-00001')->count());
            $this->assertSame(['SROne', 'SRTwo'], TechnicalReport::query()
                ->where('reference_number', 'SN-00001')->orderBy('id')->pluck('report_name')->all());
        } finally {
            @unlink($path);
        }
    }

    public function test_reimporting_the_same_file_updates_in_place_instead_of_duplicating(): void
    {
        $path = $this->workbook();

        try {
            $service = app(SourceWorkbookImportService::class);
            $service->importTechnicalReports($path);
            $service->importTechnicalReports($path);

            $this->assertSame(3, TechnicalReport::query()->count());

            $ids = TechnicalReport::query()->pluck('source_record_id')->all();
            $this->assertSame($ids, array_values(array_unique($ids)));
            $this->assertContains('row-2', $ids);
        } finally {
            @unlink($path);
        }
    }
}
