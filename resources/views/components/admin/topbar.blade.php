<header class="relative sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between gap-4 border-b border-base-300 bg-base-100/95 px-5 backdrop-blur-sm sm:px-6 xl:px-8">
    <div class="flex min-w-0 flex-1 items-center gap-3">
        <button type="button" x-on:click="mobileSidebarOpen = true" class="admin-icon-button lg:hidden" aria-label="Open navigation">
            <x-mary-icon name="o-bars-3" class="h-5 w-5" />
        </button>

        <div class="hidden min-w-0 xl:block">
            <p class="truncate text-xs font-semibold text-base-content/45">Operations workspace</p>
            <p class="truncate text-sm font-bold text-base-content">{{ now()->format('l, F j, Y') }}</p>
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-1 sm:gap-2">
        <x-mary-theme-toggle class="admin-icon-button" />

        <div class="ml-1 h-6 w-px bg-base-300"></div>

        <x-dropdown align="right" width="48">
            <x-slot name="trigger">
                <button type="button" class="flex items-center gap-2 rounded-md px-1.5 py-1.5 text-left transition hover:bg-base-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary/15 text-xs font-bold text-primary">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <span class="hidden max-w-32 sm:block">
                        <span class="block truncate text-xs font-bold text-base-content">{{ auth()->user()->name }}</span>
                        <span class="block truncate text-[11px] text-base-content/50">{{ auth()->user()->role->label() }}</span>
                    </span>
                    <x-mary-icon name="o-chevron-down" class="hidden h-4 w-4 text-base-content/45 sm:block" />
                </button>
            </x-slot>
            <x-slot name="content">
                <x-dropdown-link :href="route('profile')" wire:navigate>Profile</x-dropdown-link>
                <x-dropdown-link :href="route('settings')" wire:navigate>Settings</x-dropdown-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-start">
                        <x-dropdown-link>Log out</x-dropdown-link>
                    </button>
                </form>
            </x-slot>
        </x-dropdown>
    </div>
</header>