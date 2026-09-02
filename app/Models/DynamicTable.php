<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DynamicTable extends Model
{
    use HasFactory;

    protected $table = 'dynamic_tables';

    protected $fillable = [
        'key',
        'name',
        'description',
        'icon',
        'monday_board_id',
        'monday_field_map',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'monday_field_map' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DynamicRow::class, 'table_key', 'key');
    }

    public function columns(): HasMany
    {
        return $this->hasMany(CustomTableColumn::class, 'table_key', 'key')->orderBy('position');
    }

    /**
     * The long-lived core table keys that a user-created table can never
     * collide with (they are hardcoded Livewire subclasses + routes).
     */
    public const RESERVED_KEYS = [
        'installed-products',
        'service-requests',
        'technical-reports',
        'history-reports',
        'personnel',
    ];

    public static function isKeyAvailable(string $key): bool
    {
        if (in_array($key, self::RESERVED_KEYS, true)) {
            return false;
        }

        return ! static::query()->where('key', $key)->exists();
    }
}
