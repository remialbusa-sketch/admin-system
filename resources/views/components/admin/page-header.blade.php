@props([
    'eyebrow' => null,
    'title',
    'description' => null,
])

<header class="flex flex-col gap-4 pb-5 md:flex-row md:items-end md:justify-between">
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="mb-1 inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-[0.14em] text-primary">{{ $eyebrow }}</p>
        @endif
        <h1 class="mt-1 font-display text-2xl font-semibold tracking-tight text-base-content sm:text-[28px]">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1.5 max-w-2xl text-sm leading-6 text-base-content/60">{{ $description }}</p>
        @endif
    </div>

    @if (isset($actions))
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endif
</header>
