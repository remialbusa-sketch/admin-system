<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MondaySyncSetting extends Model
{
    use HasFactory;

    protected $table = 'monday_sync_settings';

    protected $fillable = [
        'domain',
        'board_id',
        'field_map',
        'core_field_map',
        'skipped_column_ids',
        'mapping_revision',
        'title_field',
        'enabled',
        'last_synced_at',
        'last_item_id_seen',
    ];

    protected function casts(): array
    {
        return [
            'field_map' => 'array',
            'core_field_map' => 'array',
            'skipped_column_ids' => 'array',
            'mapping_revision' => 'integer',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Fetch (or create the scaffold for) a domain's sync row.
     */
    public static function forDomain(string $domain): self
    {
        return static::firstOrCreate(['domain' => $domain]);
    }
}
