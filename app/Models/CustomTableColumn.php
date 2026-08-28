<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomTableColumn extends Model
{
    use HasFactory;

    protected $table = 'table_custom_columns';

    protected $fillable = [
        'table_key',
        'name',
        'type',
        'settings',
        'position',
        'is_required',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'position' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    /**
     * The stable key used in column definitions / filters / sorting
     * (e.g. "custom_14").
     */
    public function columnKey(): string
    {
        return 'custom_'.$this->id;
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomTableColumnValue::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
