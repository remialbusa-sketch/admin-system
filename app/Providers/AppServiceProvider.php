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
        // Dashboard grid layout engine: the expression engine and the widget
        // registry are app-wide singletons; the registry is pre-loaded from
        // config/dashboard.php so widgets can be added/removed in config.
        $this->app->singleton(\App\Support\Dashboard\ExpressionEngine::class);
        $this->app->singleton(\App\Support\Dashboard\GridLayoutNormalizer::class);
        $this->app->singleton(\App\Support\Dashboard\WidgetRegistry::class, function ($app) {
            $registry = new \App\Support\Dashboard\WidgetRegistry($app);

            foreach (config('dashboard.widgets', []) as $type => $factory) {
                $registry->register($type, $factory);
            }

            return $registry;
        });
        $this->app->singleton(\App\Support\Dashboard\DashboardLayoutEngine::class);
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
