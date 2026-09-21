<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shareable dashboards + normalized data sources.
 *
 * Replaces the per-user dashboard_layouts model (kept for one release as a
 * fallback) with:
 *   dashboards         — one row per dashboard; layout JSON holds the widgets
 *   dashboard_sources  — connected tables (alias per dashboard, unique)
 *   dashboard_shares   — people sharing (view|edit)
 *
 * Existing per-user layouts are backfilled into a personal "My dashboard"
 * with a default Product Database source (alias "pdb").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->json('layout')->nullable();
            $table->timestamps();
        });

        Schema::create('dashboard_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dashboard_id')->constrained('dashboards')->cascadeOnDelete();
            $table->string('table_key');
            $table->string('alias');
            $table->unsignedInteger('position')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['dashboard_id', 'alias']);
        });

        Schema::create('dashboard_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dashboard_id')->constrained('dashboards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission')->default('view');
            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dashboard_id', 'user_id']);
        });

        // Backfill: one personal dashboard per existing per-user layout.
        if (! Schema::hasTable('dashboard_layouts')) {
            return;
        }

        DB::table('dashboard_layouts')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                $dashboardId = DB::table('dashboards')->insertGetId([
                    'owner_id' => $row->user_id,
                    'name' => 'My dashboard',
                    'description' => null,
                    'is_system' => false,
                    'layout' => $row->layout,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);

                DB::table('dashboard_sources')->insert([
                    'dashboard_id' => $dashboardId,
                    'table_key' => 'installed-products',
                    'alias' => 'pdb',
                    'position' => 0,
                    'settings' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_shares');
        Schema::dropIfExists('dashboard_sources');
        Schema::dropIfExists('dashboards');
    }
};
