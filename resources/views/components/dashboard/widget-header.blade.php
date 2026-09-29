@props([
    'title',
    'subtitle' => null,
])

<div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
        <h3 class="font-display text-lg font-semibold tracking-tight">{{ $title }}</h3>
        @if (trim((string) $subtitle) !== '')
            <p class="mt-0.5 text-xs text-base-content/50">{{ $subtitle }}</p>
        @endif
    </div>
</div>
