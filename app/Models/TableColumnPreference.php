<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableColumnPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'table_key',
        'column_key',
        'position',
        'width',
        'hidden',
        'frozen',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'width' => 'integer',
            'hidden' => 'boolean',
            'frozen' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
