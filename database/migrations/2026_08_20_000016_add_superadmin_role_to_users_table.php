<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            ])->default('regional_manager')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', [
                'president',
                'vp_ops',
                'regional_manager',
                'national_manager',
            ])->default('regional_manager')->change();
        });
    }
};