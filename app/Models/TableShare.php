<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * People sharing: grants a user view or edit access to a user-created
 * (dynamic) table. Mirrors DashboardShare.
 */
class TableShare extends Model
{
    protected $fillable = [
        'dynamic_table_id',
        'user_id',
        'permission',
        'shared_by',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(DynamicTable::class, 'dynamic_table_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }
}
