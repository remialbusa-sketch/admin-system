<?php

namespace App\Providers;

use App\Enums\UserRole;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Aligned with ManagedTable::canEdit() — only Superadmins maintain data.
        Gate::define('import', fn ($user) => $user?->role === UserRole::Superadmin);
        Gate::define('manageUsers', fn ($user) => $user?->role === UserRole::Superadmin);
    }
}
