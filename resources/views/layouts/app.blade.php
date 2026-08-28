<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Admin System') }} - Account</title>

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
        <div
            x-data="{ sidebarCollapsed: false, mobileSidebarOpen: false }"
            x-init="
                sidebarCollapsed = window.matchMedia('(min-width: 1024px)').matches && localStorage.getItem('admin-sidebar-collapsed') === 'true';
                $watch('sidebarCollapsed', value => localStorage.setItem('admin-sidebar-collapsed', value));
            "
            x-on:keydown.escape.window="mobileSidebarOpen = false"
            class="flex min-h-screen lg:h-screen"
        >
            <x-admin.sidebar />

            <div class="flex min-w-0 min-h-0 flex-1 flex-col">
                <x-admin.topbar />

                <main class="admin-scrollbar min-h-0 flex-1 overflow-y-auto">
                    <div class="mx-auto w-full max-w-5xl p-5 sm:p-6 xl:p-8">
                        @if (isset($header))
                            <div class="mb-5 border-b border-base-300 pb-5">
                                {{ $header }}
                            </div>
                        @endif
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
