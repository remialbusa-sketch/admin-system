<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_custom_columns', function (Blueprint $table) {
            $table->id();
            $table->string('table_key')->index();
            $table->string('name');
            $table->string('type');
            $table->json('settings')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_required')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['table_key', 'name']);
            $table->index(['table_key', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_custom_columns');
    }
};
