<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalPersonnel extends Model
{
    use HasFactory;

    protected $table = 'technical_personnel';

    protected $fillable = [
        'source_system',
        'source_record_id',
        'import_batch_id',
        'name',
        'position',
        'branch',
        'region',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
