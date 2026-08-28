<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'source_system',
        'source_record_id',
        'source_hash',
        'source_updated_at',
        'customer_name',
        'customer_address',
        'hospital_section',
        'branch',
        'region',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }
}
