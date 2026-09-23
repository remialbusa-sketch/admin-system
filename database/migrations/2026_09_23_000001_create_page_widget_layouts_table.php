<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user widget grids for analytics pages (TSA, TSP, …): once a user
     * takes ownership of a curated KPI strip, its widgets live here and the
     * hardcoded cards step aside. Resetting deletes the row.
     */
    public function up(): void
    {
        Schema::create('page_widget_layouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page', 64);
            $table->json('layout');
            $table->timestamps();

            $table->unique(['user_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_widget_layouts');
    }
};
