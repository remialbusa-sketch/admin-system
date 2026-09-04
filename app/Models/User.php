<?php

namespace App\Models;

// Auth: MustVerifyEmail makes the 'verified' route middleware actually enforce
// verification (without it, 'verified' silently passes every user). Filament's
// contract gates the /admin panel to the roles below in every environment.
use App\Enums\UserPermission;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Gate the Filament /admin panel. Without this contract, Filament allows
     * every authenticated user in non-production environments.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, [UserRole::Superadmin, UserRole::President], true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'credentials_resent_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'permission' => UserPermission::class,
        ];
    }

    /**
     * May this account edit records in the managed tables (grid edits)?
     * Superadmin always can; otherwise the permission level must be editor+.
     */
    public function canEditRecords(): bool
    {
        return $this->role === UserRole::Superadmin
            || in_array($this->permission, [UserPermission::Editor, UserPermission::Admin], true);
    }

    /** May this account run workbook imports? Superadmin or admin level. */
    public function canImport(): bool
    {
        return $this->role === UserRole::Superadmin || $this->permission === UserPermission::Admin;
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'run_by');
    }
}
