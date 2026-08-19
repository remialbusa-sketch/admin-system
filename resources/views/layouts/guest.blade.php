<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Admin System') }} - Sign in</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=barlow:500,600,700|manrope:400,500,600,700&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body>
        <div class="grid min-h-screen lg:grid-cols-[minmax(360px,0.8fr)_minmax(460px,1.2fr)]">
            <aside class="hidden flex-col justify-between border-r border-base-300 bg-neutral p-8 text-neutral-content lg:flex xl:p-12">
                    <a href="/" class="flex items-center gap-3">
                    <x-admin.logo class="h-9 w-14" />
                    <span>
                        <span class="block text-sm font-bold">Admin System</span>
                        <span class="block text-[10px] font-semibold uppercase tracking-[0.16em] text-neutral-content/50">Operations</span>
                    </span>
                </a>

                <div class="max-w-md">
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">Operations workspace</p>
                    <h1 class="mt-4 font-display text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">Make every service record easier to act on.</h1>
                    <p class="mt-5 max-w-sm text-sm leading-6 text-neutral-content/65">A focused control surface for imported records, technical service personnel, and the decisions that keep work moving.</p>
                    <div class="mt-8 grid grid-cols-2 gap-x-8 gap-y-5 border-t border-neutral-content/15 pt-6">
                        <div>
                            <p class="metric-value text-2xl font-semibold">1,284</p>
                            <p class="mt-1 text-xs text-neutral-content/50">Open records</p>
                        </div>
                        <div>
                            <p class="metric-value text-2xl font-semibold">92.6%</p>
                            <p class="mt-1 text-xs text-neutral-content/50">SLA compliance</p>
                        </div>
                    </div>
                </div>

                <p class="text-xs text-neutral-content/40">Secure workspace access - {{ now()->format('Y') }}</p>
            </aside>

            <main class="flex min-h-screen flex-col bg-base-200">
                <header class="flex items-center justify-between px-5 py-5 sm:px-8 lg:justify-end lg:px-12">
                    <a href="/" class="flex items-center gap-3 lg:hidden">
                        <x-admin.logo class="h-8 w-12" />
                        <span class="text-sm font-bold text-base-content">Admin System</span>
                    </a>
                    <x-mary-theme-toggle class="admin-icon-button" />
                </header>

                <div class="flex flex-1 items-center px-5 pb-12 sm:px-8 lg:px-12">
                    <div class="w-full max-w-md">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
