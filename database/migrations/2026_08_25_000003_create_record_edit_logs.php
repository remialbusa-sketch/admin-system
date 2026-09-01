<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for manual grid edits: who changed (or created / deleted) which
 * record, when. Import-driven changes are already tracked via import_batches;
 * this covers the human edits executives ask about after a number moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_edit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('table_key');
            $table->unsignedBigInteger('row_id');
            $table->string('action'); // created | updated | deleted
            $table->string('field')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamps();

            $table->index(['table_key', 'row_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_edit_logs');
    }
};
