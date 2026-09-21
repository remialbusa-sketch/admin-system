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

    /**
     * A dashboard-unique alias derived from a base name ("service_requests",
     * then "_2", "_3", ...). Shared by the dashboard panel and the
     * visualization wizard so both always agree on widget dataset keys.
     */
    public static function uniqueAliasFor(Dashboard $dashboard, string $base): string
    {
        $base = $base !== '' ? $base : 'source';
        $candidate = $base;
        $suffix = 2;

        while ($dashboard->sources()->where('alias', $candidate)->exists()) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
