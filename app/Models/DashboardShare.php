<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * People sharing: grants a user view or edit access to a dashboard.
 */
class DashboardShare extends Model
{
    protected $fillable = [
        'dashboard_id',
        'user_id',
        'permission',
        'shared_by',
    ];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
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
