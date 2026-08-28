<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTspDisplayNames extends Command
{
    protected $signature = 'tsp:backfill-names {map? : path to a person-XXXX -> name JSON map}';
    protected $description = 'Backfill technical_reports.tsp_display_name from a person-XXXX -> name JSON map.';

    public function handle(): int
    {
        $mapPath = $this->argument('map') ?: base_path('tsp_map.json');
        if (! is_file($mapPath)) {
            $this->error("Map file not found: {$mapPath}");

            return self::FAILURE;
        }
        $map = json_decode(file_get_contents($mapPath), true);
        if (! is_array($map) || empty($map)) {
            $this->warn('Map is empty.');

            return self::SUCCESS;
        }

        $pdo = DB::getPdo();
        $cases = [];
        foreach ($map as $personId => $realName) {
            $cases[] = 'WHEN tsp_name = ' . $pdo->quote($personId) . ' THEN ' . $pdo->quote($realName);
        }
        $sql = 'UPDATE technical_reports SET tsp_display_name = CASE ' . implode(' ', $cases)
            . ' ELSE COALESCE(tsp_display_name, tsp_name) END WHERE tsp_name IN ('
            . implode(',', array_map(fn ($id) => $pdo->quote($id), array_keys($map))) . ')';
        $updated = DB::update($sql);

        $this->info('Mapped ' . count($map) . ' TSP identities; updated ' . $updated . ' technical_reports rows.');

        return self::SUCCESS;
    }
}
