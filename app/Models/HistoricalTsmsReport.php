<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalTsmsReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'source_system',
        'source_record_id',
        'source_hash',
        'source_updated_at',
        'response_timestamp',
        'csr_number',
        'problem_or_complaint',
        'brand',
        'model',
        'serial_number',
        'account_name',
        'account_address',
        'service_type',
        'status',
        'job_done',
        'parts_replaced',
        'recommendation',
        'login_at',
        'service_at',
        'logout_at',
        'tsr_number',
        'tsp_name',
        'work_with_personnel',
        'branch',
        'document_reference',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'response_timestamp' => 'datetime',
            'login_at' => 'datetime',
            'service_at' => 'datetime',
            'logout_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
