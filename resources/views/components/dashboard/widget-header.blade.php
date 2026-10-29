@props([
    'title',
    'subtitle' => null,
])

<div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
        <h3 class="font-display text-lg font-semibold tracking-tight">{{ $title }}</h3>
        @if (trim((string) $subtitle) !== '')
            <p class="mt-1 text-xs leading-5 text-base-content/70">{{ $subtitle }}</p>
        @endif
    </div>
</div>
