<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive support for the managed tables: a nullable archived_at marks a
 * record as "archived" (hidden from the default listing, restorable from the
 * archive view) without deleting it — mirroring Monday.com's Archive action.
 */
return new class extends Migration
{
    private const TABLES = [
        'installations',
        'service_requests',
        'technical_reports',
        'historical_tsms_reports',
        'technical_personnel',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('archived_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('archived_at');
            });
        }
    }
};
