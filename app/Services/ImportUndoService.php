<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\HistoricalTsmsReport;
use App\Models\ImportBatch;
use App\Models\Installation;
use App\Models\MondaySyncSetting;
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reverses workbook imports and empties tables.
 *
 * Undo relies on two facts: every imported row is stamped with its
 * import_batch_id on every touch, and rows carry created_at. Rows created
 * inside the batch window were BORN from that file (safe to delete); rows
 * created earlier were merely UPDATED by it (reported, never deleted —
 * re-import the correct file over those).
 */
class ImportUndoService
{
    /** Core table_key => domain models in FK-safe delete order. */
    public const CORE_TABLES = [
        'installed-products' => [Installation::class, Account::class],
        'service-requests' => [ServiceRequest::class],
        'technical-reports' => [TechnicalReport::class],
        'history-reports' => [HistoricalTsmsReport::class],
        'personnel' => [TechnicalPersonnel::class],
    ];

    /** Batch statuses that may be undone (never a running batch). */
    public const UNDOABLE_STATUSES = ['completed', 'completed_with_errors', 'failed'];

    /**
     * @return array{tables: array<string, array{deleted: int, updated: int}>}
     *
     * @throws RuntimeException when the batch cannot be undone
     */
    public function undo(ImportBatch $batch): array
    {
        $batch->refresh();

        if ($batch->status === 'undone') {
            throw new RuntimeException("Batch #{$batch->id} was already undone.");
        }

        if (! in_array($batch->status, self::UNDOABLE_STATUSES, true)) {
            throw new RuntimeException("Batch #{$batch->id} is still {$batch->status} — wait until it finishes.");
        }

        if ($blocker = $this->blockingSyncDomain($batch)) {
            throw new RuntimeException("Pause the monday.com sync for '{$blocker}' first — it would re-add the removed rows.");
        }

        $from = $batch->started_at ?? $batch->created_at;
        $to = $batch->completed_at;
        $report = [];

        DB::transaction(function () use ($batch, $from, $to, &$report): void {
            foreach ($this->stampedTables($batch) as $tableKey => $models) {
                $deleted = 0;
                $updated = 0;

                foreach ($models as $model) {
                    $createdIds = $model::query()
                        ->where('import_batch_id', $batch->id)
                        ->where('created_at', '>=', $from)
                        ->when($to !== null, fn ($query) => $query->where('created_at', '<=', $to))
                        ->pluck('id');

                    $updated += $model::query()
                        ->where('import_batch_id', $batch->id)
                        ->where('created_at', '<', $from)
                        ->count();

                    if ($createdIds->isEmpty()) {
                        continue;
                    }

                    $this->purgeCustomValues($tableKey, $createdIds->all());

                    foreach ($createdIds->chunk(500) as $chunk) {
                        $deleted += $this->deleteRows($model, $chunk->all());
                    }
                }

                if ($deleted > 0 || $updated > 0) {
                    $report[$tableKey] = ['deleted' => $deleted, 'updated' => $updated];
                }
            }

            $batch->update(['status' => 'undone']);
        });

        $this->invalidateCaches();

        return ['tables' => $report];
    }

    /**
     * Remove every row from a table, keeping its structure (columns for
     * dynamic tables, schema for core tables).
     *
     * @return array<string, int> table_key => rows removed
     *
     * @throws RuntimeException when a monday sync would re-add rows
     */
    public function purgeTable(string $tableKey): array
    {
        if ($blocker = $this->blockingSyncDomainForTables([$tableKey])) {
            throw new RuntimeException("Pause the monday.com sync for '{$blocker}' first — it would re-add the removed rows.");
        }

        $removed = [];

        DB::transaction(function () use ($tableKey, &$removed): void {
            $models = $this->modelsForTable($tableKey);
            $count = 0;

            foreach ($models as $model) {
                $ids = $model::query()->when(
                    $model === DynamicRow::class,
                    fn ($query) => $query->where('table_key', $tableKey),
                )->pluck('id');

                if ($ids->isEmpty()) {
                    continue;
                }

                $this->purgeCustomValues($tableKey, $ids->all());

                foreach ($ids->chunk(500) as $chunk) {
                    $count += $this->deleteRows($model, $chunk->all());
                }
            }

            $removed[$tableKey] = $count;
        });

        $this->invalidateCaches();

        return $removed;
    }

    /**
     * Tables (by key) holding rows stamped with this batch, each mapped to
     * the models to clean, in FK-safe order.
     *
     * @return array<string, array<int, class-string>>
     */
    private function stampedTables(ImportBatch $batch): array
    {
        $tables = [];

        foreach (self::CORE_TABLES as $tableKey => $models) {
            $touched = false;

            foreach ($models as $model) {
                if ($model::query()->where('import_batch_id', $batch->id)->exists()) {
                    $touched = true;

                    break;
                }
            }

            if ($touched) {
                $tables[$tableKey] = $models;
            }
        }

        $dynamicKeys = DynamicRow::query()
            ->where('import_batch_id', $batch->id)
            ->distinct()
            ->pluck('table_key');

        foreach ($dynamicKeys as $tableKey) {
            $tables[$tableKey] = [DynamicRow::class];
        }

        return $tables;
    }

    /** @return array<int, class-string> */
    private function modelsForTable(string $tableKey): array
    {
        if (isset(self::CORE_TABLES[$tableKey])) {
            return self::CORE_TABLES[$tableKey];
        }

        return [DynamicRow::class];
    }

    /** Dynamic rows are soft-deletable — undo/empty remove for real. */
    private function deleteRows(string $model, array $ids): int
    {
        $query = $model::query()->whereIntegerInRaw('id', $ids);

        return $model === DynamicRow::class ? $query->forceDelete() : $query->delete();
    }

    /** Custom values have no row FK — purge them manually before row deletes. */
    private function purgeCustomValues(string $tableKey, array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        $columnIds = CustomTableColumn::query()->where('table_key', $tableKey)->pluck('id');

        if ($columnIds->isEmpty()) {
            return;
        }

        CustomTableColumnValue::query()
            ->whereIn('row_id', $rowIds)
            ->whereIn('custom_column_id', $columnIds)
            ->delete();
    }

    /** A monday sync that would re-add removed rows blocks the operation. */
    private function blockingSyncDomain(ImportBatch $batch): ?string
    {
        return $this->blockingSyncDomainForTables(array_keys($this->stampedTables($batch)));
    }

    /** @param array<int, string> $tableKeys */
    private function blockingSyncDomainForTables(array $tableKeys): ?string
    {
        foreach ($tableKeys as $tableKey) {
            $enabled = MondaySyncSetting::query()
                ->where('domain', $tableKey)
                ->where('enabled', true)
                ->exists();

            if ($enabled) {
                return $tableKey;
            }
        }

        return null;
    }

    /** Mirror the import paths so dashboards recompute after removals. */
    private function invalidateCaches(): void
    {
        TableAggregationService::invalidate();

        foreach ([...ProductDashboardService::REGIONS, 'all'] as $scope) {
            foreach (ProductDashboardService::PERIODS as $months) {
                Cache::forget('product:summary:'.$scope.':'.$months);
            }
        }

        foreach (TechnicalServiceAnalysisService::PERIODS as $days) {
            Cache::forget('tsa:summary:'.$days);
        }
    }
}
