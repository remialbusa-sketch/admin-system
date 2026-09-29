<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\DynamicTable;
use App\Support\Dashboard\DashboardLayoutEngine;
use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\GridLayoutNormalizer;
use App\Support\Dashboard\WidgetRegistry;
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
        $this->app->singleton(ExpressionEngine::class);
        $this->app->singleton(GridLayoutNormalizer::class);
        $this->app->singleton(WidgetRegistry::class, function ($app) {
            $registry = new WidgetRegistry($app);

            foreach (config('dashboard.widgets', []) as $type => $factory) {
                $registry->register($type, $factory);
            }

            return $registry;
        });
        $this->app->singleton(DashboardLayoutEngine::class);
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
        // Importing into a user-created table needs table-level edit access
        // (owner/superadmin/edit share). Core keys resolve to no dynamic row
        // and pass — the `import` role gate above still applies to them.
        // Route middleware: can:importTable,table.
        Gate::define('importTable', function ($user, string $table): bool {
            $dynamic = DynamicTable::query()->where('key', $table)->first();

            return $dynamic === null || $dynamic->canBeEditedBy($user);
        });
    }
}
