@props([
    'icon' => 'o-inbox-stack',
    'title' => 'Nothing here yet',
    'description' => null,
])

<div {{ $attributes->class(['admin-surface flex flex-col items-center justify-center gap-2 px-6 py-12 text-center']) }}>
    <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-base-200 text-base-content/45">
        <x-mary-icon :name="$icon" class="h-5 w-5" />
    </span>
    <p class="text-sm font-bold text-base-content">{{ $title }}</p>
    @if ($description)
        <p class="max-w-sm text-xs leading-5 text-base-content/50">{{ $description }}</p>
    @endif

    @isset($slot)
        <div class="mt-2">{{ $slot }}</div>
    @endisset
</div>
