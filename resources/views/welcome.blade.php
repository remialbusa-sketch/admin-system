<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin System - Operations workspace</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=barlow:500,600,700|manrope:400,500,600,700&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <main class="min-h-screen bg-base-200">
            <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col px-5 py-6 sm:px-8 lg:px-12">
                <header class="flex items-center justify-between border-b border-base-300 pb-5">
                    <a href="/" class="flex items-center gap-3">
                        <x-admin.logo class="h-9 w-14" />
                        <span>
                            <span class="block text-sm font-bold text-base-content">Admin System</span>
                            <span class="block text-[10px] font-semibold uppercase tracking-[0.16em] text-base-content/45">Operations</span>
                        </span>
                    </a>
                    <div class="flex items-center gap-3">
                        <x-mary-theme-toggle class="admin-icon-button" />
                        @auth
                            <a href="{{ route('dashboard') }}" class="admin-primary-button">Open workspace</a>
                        @else
                            <a href="{{ route('login') }}" class="admin-primary-button">Sign in</a>
                        @endauth
                    </div>
                </header>

                <section class="grid flex-1 items-center gap-12 py-16 lg:grid-cols-[minmax(0,1.1fr)_minmax(300px,0.9fr)] lg:py-24">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">Operations workspace</p>
                        <h1 class="mt-5 max-w-3xl font-display text-5xl font-semibold leading-[1.05] tracking-tight text-base-content sm:text-6xl">Clarity for the work between intake and resolution.</h1>
                        <p class="mt-6 max-w-xl text-base leading-7 text-base-content/60">Admin System brings imported service records and TSP performance into one deliberate, readable workspace built for daily decisions.</p>
                        <div class="mt-8 flex flex-wrap items-center gap-3">
                            <a href="{{ route('login') }}" class="admin-primary-button h-10">Enter the workspace <x-mary-icon name="o-arrow-right" class="h-4 w-4" /></a>
                            <a href="{{ route('help-center') }}" class="admin-secondary-button h-10">Read the guide</a>
                        </div>
                    </div>

                    <div class="admin-surface divide-y divide-base-300">
                        <div class="p-6">
                            <p class="text-xs font-bold uppercase tracking-[0.1em] text-base-content/45">Workspace at a glance</p>
                            <p class="metric-value mt-3 text-4xl font-semibold text-base-content">92.6%</p>
                            <p class="mt-1 text-sm text-base-content/55">SLA compliance across active service records</p>
                        </div>
                        <div class="grid grid-cols-2 divide-x divide-base-300">
                            <div class="p-6"><p class="metric-value text-2xl font-semibold text-base-content">248</p><p class="mt-1 text-xs text-base-content/50">Active TSPs</p></div>
                            <div class="p-6"><p class="metric-value text-2xl font-semibold text-base-content">1,284</p><p class="mt-1 text-xs text-base-content/50">Open records</p></div>
                        </div>
                        <div class="p-6 text-xs leading-5 text-base-content/55">Import a file, add the fields your team needs, and keep the current record one click away from action.</div>
                    </div>
                </section>

                <footer class="border-t border-base-300 pt-5 text-xs text-base-content/40">Admin System - {{ now()->format('Y') }}</footer>
            </div>
        </main>
    </body>
</html>
