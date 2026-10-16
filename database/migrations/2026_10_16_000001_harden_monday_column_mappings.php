<?php

use App\Support\MondayCoreTargets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monday_sync_settings', function (Blueprint $table) {
            $table->json('core_field_map')->nullable()->after('field_map');
            $table->json('skipped_column_ids')->nullable()->after('core_field_map');
            $table->unsignedInteger('mapping_revision')->default(0)->after('skipped_column_ids');
        });

        Schema::table('monday_synced_items', function (Blueprint $table) {
            $table->unsignedInteger('mapping_revision')->default(0)->after('state');
        });

        // Freeze existing core destinations while the original local column
        // names are still available. Subsequent renames cannot redirect them.
        foreach (DB::table('monday_sync_settings')->whereNotNull('field_map')->get() as $setting) {
            if (! MondayCoreTargets::has($setting->domain)) {
                continue;
            }

            $fieldMap = json_decode($setting->field_map, true);
            if (! is_array($fieldMap) || $fieldMap === []) {
                continue;
            }

            $names = DB::table('table_custom_columns')
                ->where('table_key', $setting->domain)
                ->whereIn('id', array_values($fieldMap))
                ->pluck('name', 'id');
            $coreMap = [];
            foreach ($fieldMap as $mondayId => $customId) {
                $field = MondayCoreTargets::fieldForLabel($setting->domain, (string) ($names[$customId] ?? ''));
                if ($field !== null) {
                    $coreMap[$mondayId] = $field;
                }
            }

            DB::table('monday_sync_settings')->where('id', $setting->id)->update(['core_field_map' => json_encode($coreMap)]);
        }
    }

    public function down(): void
    {
        Schema::table('monday_synced_items', fn (Blueprint $table) => $table->dropColumn('mapping_revision'));
        Schema::table('monday_sync_settings', fn (Blueprint $table) => $table->dropColumn([
            'core_field_map', 'skipped_column_ids', 'mapping_revision',
        ]));
    }
};
