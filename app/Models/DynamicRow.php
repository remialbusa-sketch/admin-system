<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DynamicRow extends Model
{
    use HasFactory;

    protected $table = 'dynamic_rows';

    protected $fillable = [
        'table_key',
        'name',
        'source_system',
        'source_record_id',
        'source_hash',
        'source_updated_at',
        'position',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'archived_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DynamicTable::class, 'table_key', 'key');
    }

    /**
     * The custom-column values for this row. row_id has no FK (the pivot's
     * choice — the same as the managed tables), so writes/deletes must purge
     * them manually.
     */
    public function customValues(): HasMany
    {
        return $this->hasMany(CustomTableColumnValue::class, 'row_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
