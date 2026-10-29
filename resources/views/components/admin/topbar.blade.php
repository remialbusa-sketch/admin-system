@props(['title' => 'Home'])

<header class="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between gap-3 border-b border-base-300 bg-base-100 px-4 sm:px-6 xl:px-8">
    <div class="flex min-w-0 flex-1 items-center gap-3">
        <button type="button" x-on:click="sidebarCollapsed = false; mobileSidebarOpen = true" class="admin-icon-button lg:hidden" aria-label="Open navigation" aria-controls="admin-navigation" :aria-expanded="mobileSidebarOpen">
            <x-mary-icon name="o-bars-3" class="h-5 w-5" />
        </button>

        <div class="flex min-w-0 items-center gap-2 text-sm">
            <span class="hidden text-base-content/60 sm:inline">Workspace</span>
            <span class="hidden sm:block" aria-hidden="true"><x-mary-icon name="o-chevron-right" class="h-3.5 w-3.5 text-base-content/35" /></span>
            <span class="truncate font-semibold">{{ $title }}</span>
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-1 sm:gap-3">
        <time datetime="{{ now()->toDateString() }}" class="mr-2 hidden text-xs text-base-content/65 xl:block">{{ now()->format('D, M j, Y') }}</time>
        <x-mary-theme-toggle class="admin-icon-button" aria-label="Toggle color theme" />

        <div class="ml-1 h-6 w-px bg-base-300"></div>

        <x-dropdown align="right" width="48">
            <x-slot name="trigger">
                <button type="button" :aria-expanded="open" class="flex items-center gap-2.5 rounded-md p-1.5 text-left transition-colors hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-primary">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-xs font-bold text-primary" aria-hidden="true">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <span class="hidden max-w-40 sm:block">
                        <span class="block truncate text-xs font-bold text-base-content">{{ auth()->user()->name }}</span>
                        <span class="mt-0.5 block truncate text-[11px] text-base-content/65">{{ auth()->user()->role->label() }}</span>
                    </span>
                    <span class="sr-only sm:hidden">Account menu</span>
                    <x-mary-icon name="o-chevron-down" class="h-3.5 w-3.5 text-base-content/55" />
                </button>
            </x-slot>
            <x-slot name="content">
                <x-dropdown-link :href="route('profile')" wire:navigate>Profile</x-dropdown-link>
                <x-dropdown-link :href="route('settings')" wire:navigate>Settings</x-dropdown-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full px-4 py-2 text-start text-sm transition-colors hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-primary">Log out</button>
                </form>
            </x-slot>
        </x-dropdown>
    </div>
</header>
