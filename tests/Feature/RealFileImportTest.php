<?php

namespace Tests\Feature;

use App\Livewire\TechnicalReportTable;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\ImportFailure;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Local diagnostic: run the user's REAL Technical Service Reports workbook
 * (headers + first 60 data rows) through a quick import and prove whether
 * Address and the aliased fixed fields capture their data. Skipped when the
 * file is not on this machine.
 */
class RealFileImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'C:\Users\USER\Downloads\Technical_Service_Reports_Service_V1_1790700184.xlsx';

    private const ROWS = 60;

    /** Copy the real workbook's headers + first N data rows into a fresh file. */
    private function buildTrimmedWorkbook(): array
    {
        if (! is_file(self::SOURCE)) {
            $this->markTestSkipped('Source workbook not on this machine.');
        }

        $reader = IOFactory::createReaderForFile(self::SOURCE);
        $names = $reader->listWorksheetNames(self::SOURCE);
        $sheetName = collect($names)->first(fn (string $n): bool => str_contains(strtolower($n), 'technical')) ?? $names[0];
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $source = $reader->load(self::SOURCE);
        $sheet = $source->getSheetByName($sheetName);
        $highestColumn = $sheet->getHighestColumn();

        $out = new Spreadsheet;
        $copy = $out->getActiveSheet();
        $copy->setTitle('Technical Reports');

        for ($row = 1; $row <= self::ROWS; $row++) {
            foreach (range(1, Coordinate::columnIndexFromString($highestColumn)) as $colIndex) {
                $letter = Coordinate::stringFromColumnIndex($colIndex);
                $copy->setCellValue($letter.$row, $sheet->getCell($letter.$row)->getValue());
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'real-').'.xlsx';
        (new Xlsx($out))->save($path);
        $out->disconnectWorksheets();
        $source->disconnectWorksheets();

        return [$path, Coordinate::columnIndexFromString($highestColumn)];
    }

    public function test_real_workbook_quick_import_captures_address_and_aliased_fields(): void
    {
        [$path, $columnCount] = $this->buildTrimmedWorkbook();

        $this->actingAs(User::factory()->superadmin()->create());

        $uploadId = str_repeat('ee', 16);
        $this->call('POST', '/import/upload-chunk', [
            'uploadId' => $uploadId,
            'offset' => '0',
            'fileName' => 'workbook.xlsx',
        ], [], [
            'chunk' => new UploadedFile($path, 'workbook.xlsx', 'application/octet-stream', null, true),
        ])->assertOk();
        @unlink($path);

        // Reproduce the wizard's exact signature (letter => header label).
        $signature = [];
        for ($i = 1; $i <= $columnCount; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i);
            $signature[$letter] = 'header_'.$letter; // replaced below
        }

        // Re-read headers for the signature.
        $reader = IOFactory::createReaderForFile(self::SOURCE);
        $names = $reader->listWorksheetNames(self::SOURCE);
        $sheetName = collect($names)->first(fn (string $n): bool => str_contains(strtolower($n), 'technical')) ?? $names[0];
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $source = $reader->load(self::SOURCE);
        $sourceSheet = $source->getSheetByName($sheetName);
        for ($i = 1; $i <= $columnCount; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i);
            $signature[$letter] = trim((string) $sourceSheet->getCell($letter.'1')->getValue());
        }
        $source->disconnectWorksheets();

        $prepare = $this->postJson('/tables/technical-reports/import-classic/prepare', [
            'uploadId' => $uploadId,
            'originalName' => 'workbook.xlsx',
            'sheet' => 'Technical Reports',
            'headerRow' => 1,
            'dataStart' => 2,
            'autoMap' => true,
            'mapping' => [],
            'newColumns' => [],
            'columnSignature' => $signature,
        ])->assertOk()->json();

        $offset = 0;

        do {
            $chunk = $this->postJson('/tables/technical-reports/import-classic/chunk', [
                'batchId' => $prepare['batchId'], 'offset' => $offset, 'limit' => 500,
            ])->assertOk()->json();
            $offset = $chunk['nextOffset'];
        } while (! $chunk['done']);

        $finish = $this->postJson('/tables/technical-reports/import-classic/finish', [
            'batchId' => $prepare['batchId'],
        ])->assertOk()->json();

        $failures = ImportFailure::query()->get()->map(fn (ImportFailure $f): string => $f->error_message)->all();
        $total = TechnicalReport::query()->count();

        $address = CustomTableColumn::query()
            ->where('table_key', 'technical-reports')
            ->where('name', 'Address')
            ->first();

        $addressFilled = $address === null ? 0 : CustomTableColumnValue::query()
            ->where('custom_column_id', $address->id)
            ->whereNotNull('value_text')
            ->where('value_text', '!=', '')
            ->count();

        $tspFilled = TechnicalReport::query()->whereNotNull('tsp_name')->where('tsp_name', '!=', '')->count();

        dump([
            'processed' => $finish['processed'] ?? null,
            'failed_batches' => $finish['failed'] ?? null,
            'rows_written' => $total,
            'address_column_exists' => $address !== null,
            'address_rows_filled' => $addressFilled,
            'tsp_name_rows_filled' => $tspFilled,
            'failure_messages' => array_slice(array_count_values($failures), 0, 5, true),
        ]);

        $this->assertGreaterThan(0, $total, 'No rows landed: '.json_encode(array_slice($failures, 0, 3)));
        $this->assertNotNull($address, 'Address column was not created.');
        $this->assertGreaterThan(
            0,
            $addressFilled,
            'Address column exists but no values were written. failures: '.json_encode(array_slice($failures, 0, 3))
        );
        $this->assertSame([], $failures, 'Row failures: '.json_encode(array_slice($failures, 0, 5)));

        // The grid must also RENDER the stored values — empty cells in the
        // UI with data in the table would be a display-path bug.
        $html = Livewire::test(TechnicalReportTable::class)->html();
        preg_match('/data-managed-table-payload>(.*?)<\/script>/s', $html, $m);
        $payload = json_decode($m[1] ?? '{}', true);

        dump([
            'html_len' => strlen($html),
            'has_payload_marker' => str_contains($html, 'data-managed-table-payload'),
            'regex_matched' => isset($m[1]),
            'marker_context' => substr($html, max(0, strpos($html, 'data-managed-table-payload') ?: 0), 200),
            'db_report_count' => TechnicalReport::query()->count(),
        ]);

        $addressKey = collect($payload['columns'] ?? [])
            ->first(fn (array $c): bool => ($c['label'] ?? '') === 'Address')['key'] ?? null;

        dump([
            'payload_rows' => count($payload['rows'] ?? []),
            'payload_column_keys' => collect($payload['columns'] ?? [])->pluck('key')->all(),
            'address_key' => $addressKey,
            'first_rows_address' => collect($payload['rows'] ?? [])->take(3)
                ->map(fn (array $r): mixed => $addressKey === null ? null : ($r[$addressKey] ?? null))
                ->all(),
            'first_rows_id' => collect($payload['rows'] ?? [])->take(3)->pluck('id')->all(),
        ]);

        $this->assertNotEmpty($payload['rows'] ?? [], 'Grid payload has no rows at all.');
        $this->assertNotNull($addressKey, 'Address column missing from grid payload columns.');
        $this->assertNotNull(
            collect($payload['rows'])->first(fn (array $r): bool => ! empty($r[$addressKey])),
            'Grid payload rows carry no Address value despite DB values existing.'
        );
    }
}
