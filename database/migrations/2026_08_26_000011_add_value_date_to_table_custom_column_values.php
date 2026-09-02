<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original column-registry design kept a value_date shadow column so
     * date custom columns can sort and filter at the database level. The
     * pivot's table_custom_column_values only shipped value_text /
     * value_number; restore value_date so dynamic-table date columns behave.
     */
    public function up(): void
    {
        Schema::table('table_custom_column_values', function (Blueprint $table): void {
            $table->date('value_date')->nullable()->after('value_number');
            $table->index(['custom_column_id', 'value_date']);
        });
    }

    public function down(): void
    {
        Schema::table('table_custom_column_values', function (Blueprint $table): void {
            $table->dropIndex(['custom_column_id', 'value_date']);
            $table->dropColumn('value_date');
        });
    }
};
