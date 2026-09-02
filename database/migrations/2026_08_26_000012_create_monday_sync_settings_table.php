<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-domain live-pull switch for the monday.com integration. One row per
     * table/board. The scheduler reads `enabled` (+ the registry's
     * monday_board_id) before pulling; the table page's toggle flips it.
     */
    public function up(): void
    {
        Schema::create('monday_sync_settings', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('board_id')->nullable();
            // Enables the live new-item pull for this domain. Off = skip
            // entirely (no ids fetched, no quota spent).
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_item_id_seen')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monday_sync_settings');
    }
};
