<?php

namespace App\Console\Commands;

use App\Models\TechnicalReport;
use App\Support\DashboardCache;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Removes the workbook-imported copy of every technical report the monday
 * sync also holds (same reference, whitespace/case-insensitive). Values the
 * synced row lacks are copied first — fill-empty only, never overwriting
 * live board data — then the duplicate row and its custom cells are deleted.
 */
class DedupeTechnicalReports extends Command
{
    protected $signature = 'monday:dedupe-technical-reports {--apply : Copy unique values and delete the duplicated workbook rows; default only reports counts}';

    protected $description = 'Delete workbook-imported technical reports that a monday-synced row duplicates';

    /** Domain columns copied into the synced twin when it is empty. */
    private const FIELDS = [
        'service_request_number',
        'report_name',
        'ticket_status',
        'service_status',
        'customer_name',
        'service_started_at',
        'service_completed_at',
        'tsp_name',
        'tsp_display_name',
        'brand',
        'machine_type',
        'job_done',
        'parts_replaced',
        'recommendation',
        'repair_time_hours',
        'response_time_hours',
        'report_url',
    ];

    private const CHUNK = 250;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        [$pairs, $kept] = $this->pairs();
        $workbookTotal = count($pairs) + $kept;

        // Live columns receive the copied cells; trashed ones are only
        // cleaned up with their row.
        $liveColumns = DB::table('table_custom_columns')
            ->where('table_key', 'technical-reports')
            ->whereNull('deleted_at')
            ->pluck('name', 'id');
        $allColumnIds = DB::table('table_custom_columns')
            ->where('table_key', 'technical-reports')
            ->pluck('id');

        $domainCopies = array_fill_keys(self::FIELDS, 0);
        $customCopies = [];

        foreach (array_chunk($pairs, self::CHUNK, true) as $chunk) {
            $run = function () use ($chunk, $apply, $liveColumns, &$domainCopies, &$customCopies): void {
                $this->processChunk($chunk, $apply, $liveColumns, $domainCopies, $customCopies);
            };

            $apply ? DB::transaction($run) : $run();
        }

        $deletedCells = DB::table('table_custom_column_values')
            ->whereIn('row_id', array_keys($pairs))
            ->whereIn('custom_column_id', $allColumnIds)
            ->count();

        if ($apply && $pairs !== []) {
            foreach (array_chunk(array_keys($pairs), self::CHUNK) as $ids) {
                DB::transaction(function () use ($ids, $allColumnIds): void {
                    DB::table('table_custom_column_values')
                        ->whereIn('row_id', $ids)
                        ->whereIn('custom_column_id', $allColumnIds)
                        ->delete();
                    DB::table('technical_reports')->whereIn('id', $ids)->delete();
                });
            }

            DashboardCache::invalidateDomain('technical-reports');
        }

        $this->info(($apply ? 'Applied' : 'Dry run').": {$workbookTotal} workbook rows; ".count($pairs)." duplicate a monday-synced reference and {$kept} have no twin (kept).");

        foreach ($domainCopies as $field => $count) {
            if ($count > 0) {
                $this->line("  domain cell copy: {$field} = {$count}");
            }
        }

        foreach ($customCopies as $columnId => $count) {
            $this->line('  custom cell copy: '.($liveColumns[$columnId] ?? ('#'.$columnId))." = {$count}");
        }

        $this->line('  rows to delete: '.count($pairs).'; custom cells to delete: '.$deletedCells);
        $this->line('  table after cleanup: '.(TechnicalReport::count() - ($apply ? 0 : count($pairs))).' rows');

        return self::SUCCESS;
    }

    /**
     * Workbook row id => monday row id for every shared reference.
     *
     * @return array{0: array<int, int>, 1: int}
     */
    private function pairs(): array
    {
        $mondayByRef = [];

        foreach (TechnicalReport::query()->where('source_system', 'monday:technical-reports')->get(['id', 'reference_number']) as $row) {
            $key = $this->normalize($row->reference_number);

            if ($key !== '' && ! isset($mondayByRef[$key])) {
                $mondayByRef[$key] = (int) $row->id;
            }
        }

        $pairs = [];
        $kept = 0;

        foreach (TechnicalReport::query()->where('source_system', '<>', 'monday:technical-reports')->get(['id', 'reference_number']) as $row) {
            $key = $this->normalize($row->reference_number);

            if ($key !== '' && isset($mondayByRef[$key])) {
                $pairs[(int) $row->id] = $mondayByRef[$key];
            } else {
                $kept++;
            }
        }

        return [$pairs, $kept];
    }

    /**
     * @param  array<int, int>  $chunk  workbook row id => monday row id
     * @param  Collection<int, string>  $liveColumns
     * @param  array<string, int>  $domainCopies
     * @param  array<int, int>  $customCopies
     */
    private function processChunk(array $chunk, bool $apply, Collection $liveColumns, array &$domainCopies, array &$customCopies): void
    {
        $workbookIds = array_keys($chunk);
        $mondayIds = array_values($chunk);

        $workbookRows = DB::table('technical_reports')->whereIn('id', $workbookIds)->get()->keyBy('id');
        $mondayRows = DB::table('technical_reports')->whereIn('id', $mondayIds)->get()->keyBy('id');

        $workbookCells = DB::table('table_custom_column_values')
            ->whereIn('row_id', $workbookIds)
            ->get()
            ->groupBy('row_id');

        $mondayCells = [];
        foreach (DB::table('table_custom_column_values')->whereIn('row_id', $mondayIds)->get() as $cell) {
            $mondayCells[$cell->row_id][$cell->custom_column_id] = $cell;
        }

        foreach ($chunk as $workbookId => $mondayId) {
            $workbook = $workbookRows->get($workbookId);
            $monday = $mondayRows->get($mondayId);

            if (! $workbook || ! $monday) {
                continue;
            }

            $updates = [];
            foreach (self::FIELDS as $field) {
                if (trim((string) $workbook->{$field}) === '' || trim((string) $monday->{$field}) !== '') {
                    continue;
                }

                $updates[$field] = $workbook->{$field};
                $domainCopies[$field]++;
            }

            if ($apply && $updates !== []) {
                DB::table('technical_reports')->where('id', $mondayId)->update($updates);
            }

            foreach ($workbookCells->get($workbookId, collect()) as $cell) {
                if (! isset($liveColumns[$cell->custom_column_id]) || $this->emptyValue($cell->value)) {
                    continue;
                }

                $existing = $mondayCells[$mondayId][$cell->custom_column_id] ?? null;

                if ($existing !== null && ! $this->emptyValue($existing->value)) {
                    continue;
                }

                $customCopies[$cell->custom_column_id] = ($customCopies[$cell->custom_column_id] ?? 0) + 1;

                if ($apply) {
                    DB::table('table_custom_column_values')->updateOrInsert(
                        ['custom_column_id' => $cell->custom_column_id, 'row_id' => $mondayId],
                        [
                            'value' => $cell->value,
                            'value_text' => $cell->value_text,
                            'value_number' => $cell->value_number,
                            'value_date' => $cell->value_date,
                        ],
                    );
                }
            }
        }
    }

    private function normalize(mixed $reference): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) $reference)));
    }

    private function emptyValue(?string $value): bool
    {
        return $value === null || in_array($value, ['null', '[]', '{}', '""'], true);
    }
}
