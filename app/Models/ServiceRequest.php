<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'source_system',
        'source_record_id',
        'source_hash',
        'source_updated_at',
        'service_request_number',
        'service_request_code',
        'customer_name',
        'ticket_status',
        'group_status',
        'branch',
        'tsp_assignment',
        'coordinator',
        'requesting_entity',
        'requestor_name',
        'requestor_email',
        'requestor_phone',
        'region',
        'department',
        'contract_type',
        'request_type',
        'service_type',
        'brand',
        'machine_type',
        'serial_number',
        'concerns',
        'date_needed',
        'service_indicator',
        'device_ownership',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'date_needed' => 'date',
            'raw_data' => 'array',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function technicalReports(): HasMany
    {
        return $this->hasMany(TechnicalReport::class);
    }
}
