<?php

use App\Enums\UserRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Livewire\Exceptions\MethodNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'president.view' => \App\Http\Middleware\PresidentView::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Livewire's client `$wire` proxy is built on Vue's reactivity system.
        // Stale browser bundles can reflect Vue-internal keys (e.g. `__v_raw`)
        // into a component call, which the server would otherwise 500 on.
        // These keys carry no real intent, so swallow them gracefully.
        $exceptions->renderable(function (MethodNotFoundException $exception, Request $request) {
            if (str_contains($exception->getMessage(), '__v_')) {
                return response()->json([
                    'effects' => [],
                    'serverMemo' => null,
                ], 200);
            }
        });
    })->create();