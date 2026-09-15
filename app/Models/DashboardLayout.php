<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's personalized dashboard grid layout. The JSON payload is always
 * passed through GridLayoutNormalizer before storage — unknown widget types
 * are dropped and spans clamped, so a tampered payload can never inject
 * anything into the rendered grid.
 */
class DashboardLayout extends Model
{
    protected $fillable = ['user_id', 'layout'];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
