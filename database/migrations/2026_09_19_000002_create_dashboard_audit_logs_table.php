<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for dashboard edits (owner or superadmin acting on any
 * dashboard). Mutations only — views/visits are deliberately not logged.
 *
 * `dashboard_id` is nullable with nullOnDelete so a force-deleted dashboard
 * keeps its history (the snapshot column carries the name/layout context).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dashboard_id')->nullable()->constrained('dashboards')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['dashboard_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_audit_logs');
    }
};
