<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedup/audit log for inbound monday.com webhook deliveries (M-W). Monday
     * retries on timeout, so triggerUuid is the natural idempotency key — a
     * duplicate delivery is dropped, never double-imported. Payload is kept in
     * full for replay/debugging.
     */
    public function up(): void
    {
        Schema::create('monday_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('trigger_uuid')->unique();
            $table->string('event_type')->nullable();
            $table->string('item_id')->nullable();
            $table->string('domain')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monday_webhook_events');
    }
};
