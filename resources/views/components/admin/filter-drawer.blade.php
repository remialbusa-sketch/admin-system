<div
    x-data="{ open: false }"
    x-on:open-filter-drawer.window="open = true"
    x-on:close-filter-drawer.window="open = false"
    x-on:keydown.escape.window="open = false"
>
    {{-- Trigger slot is rendered by the caller; drawer lives here for focus trap --}}
    {{ $trigger ?? '' }}

    {{-- Backdrop --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        class="fixed inset-0 z-40 bg-base-content/40 backdrop-blur-[2px]"
        x-on:click="open = false"
        aria-hidden="true"
    ></div>

    {{-- Panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        x-cloak
        class="fixed inset-y-0 right-0 z-50 flex w-full max-w-[420px] flex-col bg-base-100 shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-label="Filters"
        x-trap.noscroll="open"
    >
        <div class="flex items-center justify-between border-b border-base-300 px-5 py-4">
            <div>
                <h2 class="text-sm font-bold tracking-[0.04em] text-base-content">Filters</h2>
                <p class="text-xs text-base-content/55">Refine the table — all filters are shareable via URL.</p>
            </div>
            <button type="button" x-on:click="open = false" class="admin-icon-button" aria-label="Close filters">
                <x-mary-icon name="o-x-mark" class="h-5 w-5" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-5 py-5">
            {{ $slot }}
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-base-300 bg-base-200/50 px-5 py-4">
            <button type="button" x-on:click="$wire.clearAllFilters(); open = false" class="admin-secondary-button">
                <x-mary-icon name="o-x-mark" class="h-4 w-4" />
                Clear all
            </button>
            <button type="button" x-on:click="open = false" class="admin-primary-button">
                <x-mary-icon name="o-check" class="h-4 w-4" />
                Done
            </button>
        </div>
    </div>
</div>
