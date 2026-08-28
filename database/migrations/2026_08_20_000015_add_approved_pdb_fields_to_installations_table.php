<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installations', function (Blueprint $table): void {
            $table->string('bu_no')->nullable()->after('serial_number');
            $table->string('equipment_type')->nullable()->after('bu_no');
            $table->date('uninstallation_date')->nullable()->after('installation_date');
            $table->string('deal_type')->nullable()->after('device_ownership');
            $table->unsignedSmallInteger('warranty_period_years')->nullable()->after('warranty_status');
            $table->string('pms_frequency')->nullable()->after('warranty_period_years');
            $table->string('tsp_in_charge')->nullable()->after('pms_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('installations', function (Blueprint $table): void {
            $table->dropColumn([
                'bu_no',
                'equipment_type',
                'uninstallation_date',
                'deal_type',
                'warranty_period_years',
                'pms_frequency',
                'tsp_in_charge',
            ]);
        });
    }
};