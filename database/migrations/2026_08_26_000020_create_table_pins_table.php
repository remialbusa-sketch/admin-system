<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user pinned tables for the sidebar's "Tables" group. All tables live
     * on the /tables (Import) page; a user pins the ones they want as quick
     * links in the side navigation. table_key identifies both core tables
     * (installed-products, service-requests, ...) and user-created dynamic
     * tables (their dynamic_tables.key).
     */
    public function up(): void
    {
        Schema::create('table_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('table_key');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'table_key']);
            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_pins');
    }
};
