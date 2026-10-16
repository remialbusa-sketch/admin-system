<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MondaySyncedItem extends Model
{
    use HasFactory;

    protected $table = 'monday_synced_items';

    protected $fillable = [
        'domain',
        'item_id',
        'state',
        'mapping_revision',
        'verdict',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'verdict' => 'array',
            'mapping_revision' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
