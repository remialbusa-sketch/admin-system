<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Search shadow cap: value_text is a VARCHAR(255) index column while the
     * full value lives in `value`. Longer text made MySQL reject the whole
     * row (SQLSTATE 22001, "Data too long for column 'value_text'") — 8 rows
     * died in the 2026-09-30 import. Cap at this shared write boundary so
     * every writer (import, grid edit, dynamic tables) is covered.
     */
    protected function valueText(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): mixed => $value === null || $value === ''
                ? $value
                : mb_substr((string) $value, 0, 255),
        );
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(CustomTableColumn::class, 'custom_column_id');
    }
}
