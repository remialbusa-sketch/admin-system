<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MondayWebhookEvent extends Model
{
    use HasFactory;

    protected $table = 'monday_webhook_events';

    protected $fillable = [
        'trigger_uuid',
        'event_type',
        'item_id',
        'domain',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
