<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_custom_column_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_column_id')->constrained('table_custom_columns')->cascadeOnDelete();
            $table->unsignedBigInteger('row_id');
            $table->json('value')->nullable();
            // Flattened/shadow fields so we can sort & filter at the database
            // level without needing JSON functions (mirrors ColumnType::toShadowFields()).
            $table->string('value_text')->nullable();
            $table->decimal('value_number', 18, 4)->nullable();
            $table->timestamps();

            $table->unique(['custom_column_id', 'row_id']);
            $table->index(['custom_column_id', 'value_text']);
            $table->index(['custom_column_id', 'value_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_custom_column_values');
    }
};
