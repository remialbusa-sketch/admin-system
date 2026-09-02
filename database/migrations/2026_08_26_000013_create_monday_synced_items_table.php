<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-item sync state for the monday.com new-item detection (A7). "New"
     * is defined as an item id not yet recorded here. The delta scan reads the
     * board's id list, diffs against this table, then refetches only the unseen
     * ids. Also provides an audit trail (never silently drops an item).
     */
    public function up(): void
    {
        Schema::create('monday_synced_items', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('item_id');
            // seen / imported / failed / skipped
            $table->string('state')->default('seen');
            $table->json('verdict')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monday_synced_items');
    }
};
