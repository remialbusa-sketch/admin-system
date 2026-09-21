<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete support for dashboards (archive/restore).
 *
 * Only `dashboards` gets the trait: sources/shares stay hard rows tied to
 * their parent, so a soft-deleted dashboard hides with all its children
 * intact and a restore brings them back. A forceDelete() still removes
 * them via the existing cascadeOnDelete foreign keys.
 *
 * Managed domain tables keep their separate `archived_at` convention —
 * never mix the two (see Key-Decisions).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dashboards')) {
            return;
        }

        Schema::table('dashboards', function (Blueprint $table): void {
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashboards')) {
            return;
        }

        Schema::table('dashboards', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
