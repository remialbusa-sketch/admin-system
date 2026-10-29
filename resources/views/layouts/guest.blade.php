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

        <script>
            const savedTheme = localStorage.getItem('mary-theme')?.replaceAll('"', '');
            const savedClass = localStorage.getItem('mary-class')?.replaceAll('"', '');

            if (savedTheme) document.documentElement.setAttribute('data-theme', savedTheme);
            if (savedClass) document.documentElement.className = savedClass;
        </script>
    </head>
    <body>
        <div class="relative flex min-h-screen flex-col overflow-hidden">
            <header class="relative z-10 flex items-center justify-between px-5 py-5 sm:px-8 lg:px-12">
                <a href="/" class="flex items-center gap-3">
                    <x-admin.logo class="h-9 w-14" />
                    <span>
                        <span class="block text-sm font-bold text-base-content">Admin System</span>
                        <span class="mt-1 block text-[10px] font-medium uppercase tracking-[0.14em] text-base-content/70">MCBTSi Operations</span>
                    </span>
                </a>
                <x-mary-theme-toggle class="admin-icon-button" aria-label="Toggle color theme" />
            </header>

            <main class="relative z-10 flex flex-1 items-center justify-center px-5 pb-16 sm:px-8">
                <div class="w-full max-w-md">
                    <div class="admin-surface p-8 sm:p-10">
                        {{ $slot }}
                    </div>
                    <p class="mt-6 text-center text-xs text-base-content/70">MCBTSi · Internal operations · {{ now()->format('Y') }}</p>
                </div>
            </main>
        </div>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
