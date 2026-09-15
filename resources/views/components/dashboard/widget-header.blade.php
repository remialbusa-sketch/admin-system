@props([
    'tag',
    'title',
    'subtitle' => null,
    'tone' => 'primary',
])

<div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
        <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em]" style="color: var(--color-{{ $tone }})">
            <span class="inline-block h-px w-5" style="background: var(--color-{{ $tone }}); opacity: .5"></span>{{ $tag }}
        </p>
        <h3 class="mt-1.5 font-display text-lg font-semibold tracking-tight">{{ $title }}</h3>
        @if (trim((string) $subtitle) !== '')
            <p class="mt-0.5 text-xs text-base-content/50">{{ $subtitle }}</p>
        @endif
    </div>
</div>
