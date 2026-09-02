<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomTableColumnValue extends Model
{
    use HasFactory;

    protected $table = 'table_custom_column_values';

    protected $fillable = [
        'custom_column_id',
        'row_id',
        'value',
        'value_text',
        'value_number',
        'value_date',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'value_number' => 'decimal:4',
            'value_date' => 'date:Y-m-d',
        ];
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(CustomTableColumn::class, 'custom_column_id');
    }
}
