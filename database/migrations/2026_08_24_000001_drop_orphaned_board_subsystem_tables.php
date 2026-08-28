<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Removes the unused "Board" (Monday.com-clone) subsystem and the
     * dashboard-widgets feature that depended on it. None of this was wired
     * to any route; it is being replaced by table-scoped custom columns
     * (see create_table_custom_columns_table and friends).
     */
    public function up(): void
    {
        // Children first, to respect foreign key constraints.
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
        Schema::dropIfExists('files');
        Schema::dropIfExists('column_value_history');
        Schema::dropIfExists('column_values');
        Schema::dropIfExists('items');
        Schema::dropIfExists('imports');
        Schema::dropIfExists('columns');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('monday_sync_progress');
        Schema::dropIfExists('monday_webhook_events');
        Schema::dropIfExists('boards');
    }

    public function down(): void
    {
        // Intentionally irreversible: the orphaned Board subsystem is being
        // removed for good in favor of table-scoped custom columns.
    }
};
