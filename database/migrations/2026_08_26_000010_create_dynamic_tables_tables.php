<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User-created (dynamic) tables: a registry row per table plus a generic
     * row store. Columns live in the existing table_custom_columns /
     * table_custom_column_values machinery keyed by the dynamic table's key —
     * the survival of the original 14-type column registry.
     */
    public function up(): void
    {
        Schema::create('dynamic_tables', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            // monday.com connection (board attach happens in the connect milestone).
            $table->string('monday_board_id')->nullable();
            $table->json('monday_field_map')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['created_by']);
        });

        Schema::create('dynamic_rows', function (Blueprint $table) {
            $table->id();
            $table->string('table_key')->index();
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('source_system')->nullable();
            $table->string('source_record_id')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Manual rows carry null source identity (SQLite + MySQL both
            // allow multiple NULLs in a unique constraint), so only
            // source-backed rows are constrained — exactly like the managed
            // tables' per-source identity rule.
            $table->unique(['table_key', 'source_system', 'source_record_id']);
            $table->index(['table_key', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_rows');
        Schema::dropIfExists('dynamic_tables');
    }
};
