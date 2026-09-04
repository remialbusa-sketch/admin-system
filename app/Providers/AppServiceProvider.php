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
        // Permission model: Superadmin (role) always has full access; other
        // roles act at their assigned permission level (viewer/editor/admin).
        Gate::define('import', fn ($user) => $user?->canImport() ?? false);
        Gate::define('manageUsers', fn ($user) => $user?->role === UserRole::Superadmin);
    }
}
