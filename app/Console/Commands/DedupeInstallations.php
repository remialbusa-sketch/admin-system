<?php

namespace App\Console\Commands;

use App\Services\SourceWorkbookImportService;
use Illuminate\Console\Command;

class DedupeInstallations extends Command
{
    protected $signature = 'app:dedupe-installations {--dry-run : Report what would be removed without deleting}';

    protected $description = 'Collapse duplicate product-database installation rows (same PDB row number) to the newest version';

    public function handle(SourceWorkbookImportService $importer): int
    {
        if ($this->option('dry-run')) {
            $this->warn('Dry run is informational only — the service collapses duplicates in one pass.');

            return self::SUCCESS;
        }

        $removed = $importer->dedupeProductRows();

        $this->info($removed === 0
            ? 'No duplicate installation rows found.'
            : "Removed {$removed} superseded installation row(s); the newest version of each PDB entry is kept.");

        return self::SUCCESS;
    }
}
