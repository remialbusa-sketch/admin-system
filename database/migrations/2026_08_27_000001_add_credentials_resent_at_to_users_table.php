<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the last time a Superadmin resent the credentials handoff email to
 * an existing user. Used to throttle the resend button (no need to allow
 * button-mash floods) and to show "Last sent 5 min ago" in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('credentials_resent_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('credentials_resent_at');
        });
    }
};