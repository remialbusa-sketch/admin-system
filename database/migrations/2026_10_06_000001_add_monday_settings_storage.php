<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * monday.com credentials move from .env-only to the superadmin Settings
     * page: system_settings holds the api token + global enable flag (the
     * single key/value store), and monday_sync_settings gains the canonical
     * field map (monday column id -> custom column id) + an optional title
     * field override so core tables can choose where the item name lands.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('monday_sync_settings', function (Blueprint $table) {
            $table->json('field_map')->nullable()->after('board_id');
            $table->string('title_field')->nullable()->after('field_map');
        });
    }

    public function down(): void
    {
        Schema::table('monday_sync_settings', function (Blueprint $table) {
            $table->dropColumn(['field_map', 'title_field']);
        });

        Schema::dropIfExists('system_settings');
    }
};
