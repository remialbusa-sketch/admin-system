<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_personnel', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system')->default('personnel_list');
            $table->string('source_record_id')->unique();
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('branch')->nullable();
            $table->string('region')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index(['source_system', 'source_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_personnel');
    }
};
