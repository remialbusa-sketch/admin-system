<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordEditLog extends Model
{
    protected $fillable = [
        'user_id',
        'table_key',
        'row_id',
        'action',
        'field',
        'old_value',
        'new_value',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
