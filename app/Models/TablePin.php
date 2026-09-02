<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TablePin extends Model
{
    use HasFactory;

    protected $table = 'table_pins';

    protected $fillable = [
        'user_id',
        'table_key',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The pinned keys for a user, in sidebar order.
     *
     * @return array<int, string>
     */
    public static function keysFor(int $userId): array
    {
        return static::query()
            ->where('user_id', $userId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('table_key')
            ->all();
    }
}
