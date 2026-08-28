<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_reports', function (Blueprint $table): void {
            $table->string('tsp_display_name')->nullable()->after('tsp_name');
        });
    }

    public function down(): void
    {
        Schema::table('technical_reports', function (Blueprint $table): void {
            $table->dropColumn('tsp_display_name');
        });
    }
};
