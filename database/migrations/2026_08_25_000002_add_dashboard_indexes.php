<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns the executive dashboards and analytics aggregate on
 * every render. Without them, status/region/timestamp group-bys and filters
 * run as full scans (the slowest measured query took ~43 ms on a 172 MB
 * database; these turn it into an index-covered aggregate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->index('source_updated_at');
            $table->index('created_at');
            $table->index('region');
        });

        Schema::table('technical_reports', function (Blueprint $table) {
            $table->index('service_completed_at');
            $table->index('service_started_at');
            $table->index('tsp_name');
            $table->index('repair_time_hours');
        });

        Schema::table('historical_tsms_reports', function (Blueprint $table) {
            $table->index('service_type');
            $table->index('status');
        });

        Schema::table('installations', function (Blueprint $table) {
            $table->index('device_status');
            $table->index('warranty_status');
            $table->index('pms_frequency');
        });

        Schema::table('technical_personnel', function (Blueprint $table) {
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex(['source_updated_at']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['region']);
        });

        Schema::table('technical_reports', function (Blueprint $table) {
            $table->dropIndex(['service_completed_at']);
            $table->dropIndex(['service_started_at']);
            $table->dropIndex(['tsp_name']);
            $table->dropIndex(['repair_time_hours']);
        });

        Schema::table('historical_tsms_reports', function (Blueprint $table) {
            $table->dropIndex(['service_type']);
            $table->dropIndex(['status']);
        });

        Schema::table('installations', function (Blueprint $table) {
            $table->dropIndex(['device_status']);
            $table->dropIndex(['warranty_status']);
            $table->dropIndex(['pms_frequency']);
        });

        Schema::table('technical_personnel', function (Blueprint $table) {
            $table->dropIndex(['region']);
        });
    }
};
