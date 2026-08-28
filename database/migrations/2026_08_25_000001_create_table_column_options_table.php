<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_column_options', function (Blueprint $table) {
            $table->id();
            $table->string('table_key')->index();
            $table->string('column_key')->index();
            $table->string('label');
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['table_key', 'column_key', 'label']);
            $table->index(['table_key', 'column_key', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_column_options');
    }
};
