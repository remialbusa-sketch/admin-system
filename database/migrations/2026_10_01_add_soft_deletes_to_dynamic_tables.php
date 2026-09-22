<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_tables', function (Blueprint $table): void {
            $table->softDeletes()->after('created_by');
        });

        Schema::table('dynamic_rows', function (Blueprint $table): void {
            $table->softDeletes()->after('archived_at');
        });

        Schema::table('table_custom_columns', function (Blueprint $table): void {
            $table->softDeletes()->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_tables', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('dynamic_rows', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('table_custom_columns', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};