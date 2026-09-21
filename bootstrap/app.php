<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust only the actual proxy when deployed behind one (ngrok, a load
        // balancer). The proxy list comes from config/trustedproxy.php, which
        // reads TRUSTED_PROXIES after .env has loaded — this file is evaluated
        // BEFORE .env, so env() here would always return empty.
        // Trusting '*' on a public network lets clients spoof X-Forwarded-For
        // and rotate IPs past the login rate limiter.
        $middleware->trustProxies();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // NOTE: a renderable handler used to swallow MethodNotFoundException
        // messages containing "__v_" and return a Livewire-2-shaped payload
        // ({"effects":[],"serverMemo":null}). Livewire 3 responses must carry
        // a `components` array, so that payload crashed the client request
        // pool ("Cannot read properties of undefined (reading 'shift')").
        // The Vue-internal method leaks were fixed at the source (the grid no
        // longer stores the $wire proxy on Alpine-reactive data), so a real
        // MethodNotFound now returns an honest error instead of a malformed
        // 200 response.
    })->create();
