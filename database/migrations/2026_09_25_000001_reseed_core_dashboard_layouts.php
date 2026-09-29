<?php

use App\Support\SystemDashboards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three system rows become the single shared layout store behind their
 * canonical pages (Home / TSA / TSP): reseed their layouts with the shipped
 * page widgets — the pages render the rows' bare metric vocabulary, so the
 * old source-aliased (`tr.*`) seeded layouts would render as error cards —
 * and drop Home's seeded `pdb` source so its widget settings keep the full
 * Product Database vocabulary (source-connected boards shrink the vocabulary
 * to `pdb.*` only).
 *
 * Idempotent: rows are matched by (is_system, name); anything never seeded
 * by the earlier migration is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dashboards')) {
            return;
        }

        foreach (SystemDashboards::definitions() as $definition) {
            $id = DB::table('dashboards')
                ->where('is_system', true)
                ->where('name', $definition['name'])
                ->value('id');

            if ($id === null) {
                continue;
            }

            DB::table('dashboards')->where('id', $id)->update([
                'layout' => json_encode($definition['layout']),
                'updated_at' => now(),
            ]);

            if ($definition['name'] === 'Home') {
                DB::table('dashboard_sources')->where('dashboard_id', $id)->delete();
            }
        }
    }

    public function down(): void
    {
        // Seed-only: the previous layouts existed only inside the (already
        // updated) SystemDashboards definitions, and admin curation of these
        // shared rows must not be rolled back destructively.
    }
};
