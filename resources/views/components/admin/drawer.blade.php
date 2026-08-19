@props([
    'name',
    'title',
    'description' => null,
])

<div
    x-data="{ open: false }"
    x-on:open-drawer.window="if ($event.detail.name === @js($name)) open = true"
    x-on:close-drawer.window="if ($event.detail.name === @js($name)) open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $name }}-title"
>
    <button type="button" x-on:click="open = false" class="absolute inset-0 bg-neutral/35" aria-label="Close panel"></button>

    <aside
        x-show="open"
        x-transition:enter="transform transition duration-200 ease-out"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transform transition duration-150 ease-in"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col border-l border-base-300 bg-base-100"
    >
        <div class="flex items-start justify-between gap-4 border-b border-base-300 px-5 py-4 sm:px-6">
            <div class="min-w-0">
                <h2 id="{{ $name }}-title" class="truncate text-base font-bold text-base-content">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-1 text-xs leading-5 text-base-content/60">{{ $description }}</p>
                @endif
            </div>
            <button type="button" x-on:click="open = false" class="admin-icon-button -mr-2 -mt-2 shrink-0" aria-label="Close panel">
                <x-mary-icon name="o-x-mark" class="h-5 w-5" />
            </button>
        </div>
        <div class="admin-scrollbar flex-1 overflow-y-auto p-5 sm:p-6">
            {{ $slot }}
        </div>
    </aside>
</div>
