<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableColumnOption extends Model
{
    use HasFactory;

    protected $table = 'table_column_options';

    protected $fillable = [
        'table_key',
        'column_key',
        'label',
        'position',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
