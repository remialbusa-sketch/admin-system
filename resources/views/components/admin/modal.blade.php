@props([
    'name',
    'title',
    'description' => null,
    'size' => 'md',
])

@php
    $sizeClass = match ($size) {
        'sm' => 'max-w-md',
        'lg' => 'max-w-2xl',
        default => 'max-w-lg',
    };
@endphp

<div
    x-data="{ open: false }"
    x-on:open-modal.window="if ($event.detail.name === @js($name)) open = true"
    x-on:close-modal.window="if ($event.detail.name === @js($name)) open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $name }}-title"
>
    <button type="button" x-on:click="open = false" class="absolute inset-0 cursor-default bg-neutral/45" aria-label="Close dialog"></button>

    <div
        x-show="open"
        x-transition:enter="transition duration-150 ease-out"
        x-transition:enter-start="translate-y-2 opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition duration-100 ease-in"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-2 opacity-0"
        class="{{ $sizeClass }} relative w-full border border-base-300 bg-base-100"
    >
        <div class="flex items-start justify-between gap-4 border-b border-base-300 px-5 py-4">
            <div>
                <h2 id="{{ $name }}-title" class="text-base font-bold text-base-content">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-1 text-xs leading-5 text-base-content/60">{{ $description }}</p>
                @endif
            </div>
            <button type="button" x-on:click="open = false" class="admin-icon-button -mr-2 -mt-2" aria-label="Close dialog">
                <x-mary-icon name="o-x-mark" class="h-5 w-5" />
            </button>
        </div>
        <div class="p-5">
            {{ $slot }}
        </div>
    </div>
</div>
