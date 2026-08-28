<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source_system');
            $table->string('source_name');
            $table->string('source_sheet')->nullable();
            $table->string('source_path')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('metadata')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['source_system', 'source_name']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('import_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('source_record_id')->nullable();
            $table->string('error_type')->default('validation');
            $table->text('error_message');
            $table->json('raw_data')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['import_batch_id', 'row_number']);
            $table->index(['import_batch_id', 'source_record_id']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_system');
            $table->string('source_record_id');
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('customer_name')->nullable();
            $table->text('customer_address')->nullable();
            $table->string('hospital_section')->nullable();
            $table->string('branch')->nullable();
            $table->string('region')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'source_record_id']);
            $table->index(['customer_name', 'branch']);
            $table->index(['region']);
        });

        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_system');
            $table->string('source_record_id');
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('device_description')->nullable();
            $table->string('brand')->nullable();
            $table->string('machine_type')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('installation_date')->nullable();
            $table->string('device_status')->nullable();
            $table->string('device_ownership')->nullable();
            $table->string('charge_to')->nullable();
            $table->string('warranty_status')->nullable();
            $table->date('warranty_end_date')->nullable();
            $table->string('service_contract_status')->nullable();
            $table->decimal('service_contract_amount', 15, 2)->nullable();
            $table->date('service_contract_start')->nullable();
            $table->date('service_contract_end')->nullable();
            $table->decimal('annual_bu_charge', 15, 2)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'source_record_id']);
            $table->index(['account_id', 'serial_number']);
            $table->index(['brand', 'machine_type']);
        });

        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_system');
            $table->string('source_record_id');
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('service_request_number')->nullable();
            $table->string('service_request_code')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('ticket_status')->nullable();
            $table->string('group_status')->nullable();
            $table->string('branch')->nullable();
            $table->string('tsp_assignment')->nullable();
            $table->string('coordinator')->nullable();
            $table->string('requesting_entity')->nullable();
            $table->string('requestor_name')->nullable();
            $table->string('requestor_email')->nullable();
            $table->string('requestor_phone')->nullable();
            $table->string('region')->nullable();
            $table->string('department')->nullable();
            $table->string('contract_type')->nullable();
            $table->string('request_type')->nullable();
            $table->string('service_type')->nullable();
            $table->string('brand')->nullable();
            $table->string('machine_type')->nullable();
            $table->string('serial_number')->nullable();
            $table->text('concerns')->nullable();
            $table->date('date_needed')->nullable();
            $table->string('service_indicator')->nullable();
            $table->string('device_ownership')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'source_record_id']);
            $table->index(['service_request_number']);
            $table->index(['group_status', 'ticket_status']);
            $table->index(['branch', 'region']);
        });

        Schema::create('technical_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_system');
            $table->string('source_record_id');
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('service_request_number')->nullable();
            $table->string('report_name')->nullable();
            $table->string('ticket_status')->nullable();
            $table->string('service_status')->nullable();
            $table->string('customer_name')->nullable();
            $table->timestamp('service_started_at')->nullable();
            $table->timestamp('service_completed_at')->nullable();
            $table->string('tsp_name')->nullable();
            $table->string('brand')->nullable();
            $table->string('machine_type')->nullable();
            $table->text('job_done')->nullable();
            $table->text('parts_replaced')->nullable();
            $table->text('recommendation')->nullable();
            $table->decimal('repair_time_hours', 10, 2)->nullable();
            $table->decimal('response_time_hours', 10, 2)->nullable();
            $table->text('report_url')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'source_record_id']);
            $table->index(['reference_number']);
            $table->index(['service_request_id']);
            $table->index(['service_request_number']);
            $table->index(['service_status', 'ticket_status']);
        });

        Schema::create('historical_tsms_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_system');
            $table->string('source_record_id');
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('response_timestamp')->nullable();
            $table->string('csr_number')->nullable();
            $table->text('problem_or_complaint')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('account_name')->nullable();
            $table->text('account_address')->nullable();
            $table->string('service_type')->nullable();
            $table->string('status')->nullable();
            $table->text('job_done')->nullable();
            $table->text('parts_replaced')->nullable();
            $table->text('recommendation')->nullable();
            $table->timestamp('login_at')->nullable();
            $table->timestamp('service_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->string('tsr_number')->nullable();
            $table->string('tsp_name')->nullable();
            $table->string('work_with_personnel')->nullable();
            $table->string('branch')->nullable();
            $table->text('document_reference')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'source_record_id']);
            $table->index(['response_timestamp']);
            $table->index(['csr_number']);
            $table->index(['tsr_number']);
            $table->index(['serial_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_tsms_reports');
        Schema::dropIfExists('technical_reports');
        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('installations');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('import_failures');
        Schema::dropIfExists('import_batches');
    }
};
