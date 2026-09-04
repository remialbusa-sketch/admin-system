<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the role enum with the operational roles (service coordinator,
 * assistant coordinator, assistant) and add the permission level column
 * (viewer / editor / admin). Existing Superadmins are grandfathered to
 * 'admin' so they keep full editing access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', [
                'president',
                'superadmin',
                'vp_ops',
                'regional_manager',
                'national_manager',
                'service_coordinator',
                'assistant_coordinator',
                'assistant',
            ])->default('regional_manager')->change();

            $table->string('permission', 20)->default('viewer')->after('role');
        });

        // Grandfather existing superadmins to admin-level access.
        DB::table('users')->where('role', 'superadmin')->update(['permission' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('permission');
            $table->enum('role', [
                'president',
                'superadmin',
                'vp_ops',
                'regional_manager',
                'national_manager',
            ])->default('regional_manager')->change();
        });
    }
};