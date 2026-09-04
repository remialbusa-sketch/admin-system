<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

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

    /**
     * Whether the pinned-tables feature is usable at all. The sidebar renders
     * on every page, so if the table_pins migration hasn't run yet (a machine
     * that pulled code but skipped `php artisan migrate`, or a deploy that
     * didn't run migrations), the feature must degrade to "no pins" instead of
     * 500ing the whole app.
     */
    public static function available(): bool
    {
        return Schema::hasTable((new static)->getTable());
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
        if (! static::available()) {
            return [];
        }

        return static::query()
            ->where('user_id', $userId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('table_key')
            ->all();
    }
}
