<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DynamicTable extends Model
{
    use HasFactory;
    use SoftDeletes;

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

    public function shares(): HasMany
    {
        return $this->hasMany(TableShare::class, 'dynamic_table_id');
    }

    public const PERMISSION_VIEW = 'view';

    public const PERMISSION_EDIT = 'edit';

    /**
     * The effective permission $user has on this table: edit for the
     * superadmin and the owner, whatever an explicit share grants, null
     * when nobody shared it with them (ownerless tables are therefore
     * superadmin-only). Mirrors Dashboard::permissionFor.
     */
    public function permissionFor(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($user->isSuperadmin()) {
            return self::PERMISSION_EDIT;
        }

        if ($this->created_by !== null && $this->created_by === $user->id) {
            return self::PERMISSION_EDIT;
        }

        $share = $this->shares()->where('user_id', $user->id)->first();

        if ($share === null) {
            return null;
        }

        return $share->permission === self::PERMISSION_EDIT
            ? self::PERMISSION_EDIT
            : self::PERMISSION_VIEW;
    }

    public function canBeViewedBy(?User $user): bool
    {
        return $this->permissionFor($user) !== null;
    }

    public function canBeEditedBy(?User $user): bool
    {
        return $this->permissionFor($user) === self::PERMISSION_EDIT;
    }

    /**
     * The tables a user may open: everything for a superadmin, otherwise
     * their own plus the ones shared with them.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperadmin()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('created_by', $user->id)
                ->orWhereHas('shares', fn (Builder $share) => $share->where('user_id', $user->id));
        });
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
