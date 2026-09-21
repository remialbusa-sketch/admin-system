<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shareable dashboard. Layout JSON holds the widget grid
 * ({version, widgets:[{id,type,w,h,props}]}); data sources and shares are
 * normalized rows so they can be queried and permissioned.
 */
class Dashboard extends Model
{
    public const PERMISSION_VIEW = 'view';

    public const PERMISSION_EDIT = 'edit';

    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'is_system',
        'layout',
    ];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'is_system' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(DashboardSource::class)->orderBy('position');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(DashboardShare::class);
    }

    /**
     * The effective permission for a user: owner = edit, a share grants its
     * own level, system dashboards are viewable by everyone, and everyone
     * else gets null (no access).
     */
    public function permissionFor(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($this->owner_id === $user->id) {
            return self::PERMISSION_EDIT;
        }

        $share = $this->shares()->where('user_id', $user->id)->first();

        if ($share !== null) {
            return $share->permission === self::PERMISSION_EDIT
                ? self::PERMISSION_EDIT
                : self::PERMISSION_VIEW;
        }

        return $this->is_system ? self::PERMISSION_VIEW : null;
    }

    public function canBeViewedBy(?User $user): bool
    {
        return $this->permissionFor($user) !== null;
    }

    public function canBeEditedBy(?User $user): bool
    {
        return $this->permissionFor($user) === self::PERMISSION_EDIT;
    }
}
