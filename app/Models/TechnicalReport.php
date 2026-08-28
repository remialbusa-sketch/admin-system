<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_request_id',
        'import_batch_id',
        'source_system',
        'source_record_id',
        'source_hash',
        'source_updated_at',
        'reference_number',
        'service_request_number',
        'report_name',
        'ticket_status',
        'service_status',
        'customer_name',
        'service_started_at',
        'service_completed_at',
        'tsp_name',
        'tsp_display_name',
        'brand',
        'machine_type',
        'job_done',
        'parts_replaced',
        'recommendation',
        'repair_time_hours',
        'response_time_hours',
        'report_url',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'service_started_at' => 'datetime',
            'service_completed_at' => 'datetime',
            'repair_time_hours' => 'decimal:2',
            'response_time_hours' => 'decimal:2',
            'raw_data' => 'array',
        ];
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
