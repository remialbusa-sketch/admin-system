<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A table connected to a dashboard. `alias` is the per-dashboard name widgets
 * reference (e.g. "pdb.brands"); `table_key` resolves through TableCatalog.
 */
class DashboardSource extends Model
{
    protected $fillable = [
        'dashboard_id',
        'table_key',
        'alias',
        'position',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'position' => 'integer',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }
}
