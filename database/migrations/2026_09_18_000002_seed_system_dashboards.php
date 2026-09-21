<?php

use App\Support\SystemDashboards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the three curated analytics experiences as system dashboards
 * (ownerless, is_system = true). They appear in the dashboards index for
 * everyone; opening one creates an editable personal copy
 * (Dashboard::mount), leaving the shared template intact.
 *
 * Idempotent: existing system dashboards with the same name are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dashboards')) {
            return;
        }

        foreach (SystemDashboards::definitions() as $definition) {
            $exists = DB::table('dashboards')
                ->where('is_system', true)
                ->where('name', $definition['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            $dashboardId = DB::table('dashboards')->insertGetId([
                'owner_id' => null,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'is_system' => true,
                'layout' => json_encode($definition['layout']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($definition['sources'] as $position => $source) {
                DB::table('dashboard_sources')->insert([
                    'dashboard_id' => $dashboardId,
                    'table_key' => $source['table_key'],
                    'alias' => $source['alias'],
                    'position' => $position,
                    'settings' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashboards')) {
            return;
        }

        foreach (SystemDashboards::definitions() as $definition) {
            $id = DB::table('dashboards')
                ->where('is_system', true)
                ->where('name', $definition['name'])
                ->value('id');

            if ($id !== null) {
                DB::table('dashboard_sources')->where('dashboard_id', $id)->delete();
                DB::table('dashboards')->where('id', $id)->delete();
            }
        }
    }
};
