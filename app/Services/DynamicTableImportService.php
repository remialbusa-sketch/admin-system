<?php

namespace App\Services;

use App\ColumnTypes\Contracts\ColumnType;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\ImportBatch;
use App\Models\ImportFailure;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Imports an Excel/CSV export into a user-created (dynamic) table. This backs
 * the "Import (backfill)" action on a dynamic table page — e.g. the owner
 * re-exports a monday.com board and imports it here. Rows are upserted by
 * (table_key, source_system, source_record_id) so re-imports update instead of
 * duplicating, mirroring the managed-table identity rule.
 *
 * Mapping targets per row:
 *   - 'name'         -> dynamic_rows.name
 *   - '__identity__' -> dynamic_rows.source_record_id (monday item id, etc.)
 *   - 'custom_{id}'  -> the custom column with that id
 */
class DynamicTableImportService
{
    public function import(string $path, string $tableKey, string $sheet, array $mapping, int $headerRow, int $dataStart, ?int $userId = null): ImportBatch
    {
        $columns = CustomTableColumn::query()
            ->where('table_key', $tableKey)
            ->orderBy('position')
            ->get();

        // target key => column letter (uppercased, '' ignored)
        $mapping = collect($mapping)
            ->map(fn ($letter): string => strtoupper(trim((string) $letter)))
            ->filter(fn (string $letter): bool => $letter !== '')
            ->all();

        $batch = ImportBatch::create([
            'source_system' => 'dynamic',
            'source_name' => basename($path),
            'source_sheet' => $sheet,
            'source_path' => $path,
            'status' => 'pending',
            'metadata' => [
                'target_table' => $tableKey,
                'import_mode' => 'upsert',
                'raw_row_preserved' => true,
                'source_file_sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            ],
            'run_by' => $userId,
        ]);

        $registry = app(ColumnTypeRegistry::class);
        $identityLetter = $mapping['__identity__'] ?? null;

        $ok = 0;
        $failed = 0;
        $flush = function () use ($batch, &$ok, &$failed): void {
            if ($ok > 0) {
                $batch->increment('processed_rows', $ok);
                $ok = 0;
            }
            if ($failed > 0) {
                $batch->increment('failed_rows', $failed);
                $failed = 0;
            }
        };

        try {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
                $handle = fopen($path, 'rb');
                for ($line = 1; $line < $dataStart; $line++) {
                    fgetcsv($handle);
                }
                $rowNumber = $dataStart - 1;
                $sinceFlush = 0;

                while (($row = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                        continue;
                    }
                    $this->importRow($batch, $tableKey, $columns, $mapping, $identityLetter, $rowNumber, $row, $registry) ? $ok++ : $failed++;
                    if (++$sinceFlush >= 250) {
                        $flush();
                        $sinceFlush = 0;
                    }
                }

                fclose($handle);
            } else {
                $reader = IOFactory::createReaderForFile($path);
                $reader->setReadDataOnly(true);
                $reader->setLoadSheetsOnly([$sheet]);
                $reader->setReadFilter(new ChunkReadFilter($dataStart, PHP_INT_MAX));
                $workbook = $reader->load($path);
                $worksheet = $workbook->getSheetByName($sheet);

                if ($worksheet) {
                    $rowNumber = $dataStart - 1;
                    $sinceFlush = 0;
                    foreach ($worksheet->toArray(null, true, true, true) as $row) {
                        $rowNumber++;
                        if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                            continue;
                        }
                        $this->importRow($batch, $tableKey, $columns, $mapping, $identityLetter, $rowNumber, $row, $registry) ? $ok++ : $failed++;
                        if (++$sinceFlush >= 250) {
                            $flush();
                            $sinceFlush = 0;
                        }
                    }
                }

                $workbook->disconnectWorksheets();
                unset($worksheet, $workbook);
            }

            $flush();

            $batch->update([
                'status' => $batch->failed_rows > 0 ? 'completed_with_errors' : 'completed',
                'completed_at' => now(),
            ]);

            return $batch->refresh();
        } catch (Throwable $exception) {
            $flush();
            $batch->update(['status' => 'failed', 'completed_at' => now()]);
            ImportFailure::query()->create([
                'import_batch_id' => $batch->id,
                'error_type' => 'configuration',
                'error_message' => Str::limit($exception->getMessage(), 1000),
            ]);

            return $batch->refresh();
        }
    }

    /**
     * @param  array<string, string>  $mapping  target key => column letter
     * @param  array<int, CustomTableColumn>  $columns
     */
    private function importRow(ImportBatch $batch, string $tableKey, $columns, array $mapping, ?string $identityLetter, int $rowNumber, array $row, ColumnTypeRegistry $registry): bool
    {
        $cell = function (string $letter) use ($row): mixed {
            // XLSX sheets (toArray with useColumnIndexAsKeys) are keyed by the
            // sheet's column letters (A, B, …); CSV rows fgetcsv are indexed
            // numerically (0, 1, …), so translate the letter when absent.
            if (array_key_exists($letter, $row)) {
                return $row[$letter];
            }

            $index = Coordinate::columnIndexFromString($letter) - 1;

            return $row[$index] ?? null;
        };

        $rawValues = [];
        foreach ($mapping as $target => $letter) {
            if ($target === '__identity__' || $target === 'name') {
                continue;
            }
            $rawValues[$target] = $cell($letter);
        }

        // Stable identity: explicit identity column if mapped, else a digest of
        // every mapped raw value so re-imports of the same row stay idempotent.
        $identity = trim((string) ($identityLetter ? $cell($identityLetter) : ''));
        if ($identity === '') {
            $identity = hash('sha256', json_encode($rawValues, JSON_UNESCAPED_UNICODE));
        }

        $name = trim((string) ($mapping['name'] ?? null ? $cell($mapping['name']) : ''));
        if ($name === '') {
            $name = $identity;
        }

        $row = DynamicRow::query()->updateOrCreate(
            ['table_key' => $tableKey, 'source_system' => 'dynamic:'.$tableKey, 'source_record_id' => $identity],
            [
                'name' => Str::limit($name, 255),
            ],
        );
        $row->import_batch_id = $batch->id;
        $row->save();

        $failed = false;
        foreach ($rawValues as $target => $value) {
            $columnId = (int) Str::after($target, 'custom_');
            $column = $columns->firstWhere('id', $columnId);

            if (! $column) {
                $failed = true;

                continue;
            }

            try {
                $type = $registry->resolve($column->type);
                $validated = $this->isEmpty($value) ? [] : $type->validate($value, $column->settings ?? []);
                $this->writeValue($row, $column, $type, $validated);
            } catch (Throwable $exception) {
                $failed = true;
                ImportFailure::query()->create([
                    'import_batch_id' => $batch->id,
                    'row_number' => $rowNumber,
                    'source_record_id' => $identity,
                    'error_type' => 'validation',
                    'error_message' => Str::limit($column->name.': '.$exception->getMessage(), 500),
                    'raw_data' => ['column_id' => $column->id],
                ]);
            }
        }

        return ! $failed;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && in_array(trim($value), ['N/A', 'NA', '-', '#N/A', 'null'], true);
    }

    private function writeValue(DynamicRow $row, CustomTableColumn $column, ColumnType $type, array $validated): void
    {
        $shadow = $type->toShadowFields($validated);

        CustomTableColumnValue::query()->updateOrCreate(
            ['custom_column_id' => $column->id, 'row_id' => $row->getKey()],
            [
                'value' => $validated,
                'value_text' => $shadow['value_text'] ?? null,
                'value_number' => $shadow['value_number'] ?? null,
                'value_date' => $shadow['value_date'] ?? null,
            ],
        );
    }
}
