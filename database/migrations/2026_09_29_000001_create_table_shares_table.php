<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People sharing for user-created (dynamic) tables — mirrors
 * dashboard_shares: a per-user view|edit grant on one table. Personal
 * tables stay private to their owner (plus superadmin) until shared here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dynamic_table_id')->constrained('dynamic_tables')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission')->default('view');
            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dynamic_table_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_shares');
    }
};
