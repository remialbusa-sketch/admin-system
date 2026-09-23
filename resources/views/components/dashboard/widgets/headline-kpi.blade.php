@php
    $ragBg = ['green' => 'bg-success', 'amber' => 'bg-warning', 'red' => 'bg-error'];
    $ragText = ['green' => 'text-success', 'amber' => 'text-warning', 'red' => 'text-error'];
    $ragGlyph = ['green' => '✓', 'amber' => '!', 'red' => '✕'];
    $ragLabel = ['green' => 'on track', 'amber' => 'watch', 'red' => 'critical'];
    $rag = in_array($rag ?? 'green', ['green', 'amber', 'red'], true) ? $rag : 'green';
@endphp
@if (($variant ?? 'card') === 'lead')
    <div class="relative h-full">
        <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-primary/[0.07] blur-2xl"></div>
        <div class="relative">
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-base-content/50">{{ $label }}</p>
                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-success/15 text-[10px] font-black text-success" title="{{ $ragLabel[$rag] }}">{{ $ragGlyph[$rag] }}</span>
            </div>
            <p class="mt-5 font-display text-6xl font-semibold leading-none tracking-tight tabular-nums text-base-content sm:text-7xl">{{ $value }}</p>
            @if (($caption ?? '') !== '')
                <p class="mt-2 text-xs uppercase tracking-[0.1em] text-base-content/40">{{ $caption }}</p>
            @endif
            <div class="mt-6 flex flex-wrap gap-2">
                @if (($context ?? '') !== '')
                    <span class="rounded-full bg-primary/10 px-3 py-1 text-[11px] font-bold text-primary">{{ $context }}</span>
                @endif
                @if (($href ?? null) !== null)
                    <a href="{{ $href }}" wire:navigate class="inline-flex items-center gap-1 text-[11px] font-semibold text-base-content/50 underline-offset-2 hover:text-primary hover:underline">Open table <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5" /></a>
                @endif
            </div>
        </div>
    </div>
@else
    @php $tag = ($href ?? null) !== null ? 'a' : 'div'; @endphp
    <{{ $tag }} @if (($href ?? null) !== null) href="{{ $href }}" wire:navigate @endif
         class="flex h-full flex-col justify-between {{ ($href ?? null) !== null ? 'cursor-pointer transition duration-200 hover:-translate-y-1 hover:bg-base-200/40 hover:shadow-lg hover:shadow-primary/10' : '' }}">
        <div class="flex items-start justify-between gap-3">
            <p class="min-w-0 truncate text-[11px] font-bold uppercase tracking-[0.14em] text-base-content/45">{{ $label }}</p>
            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full {{ $ragBg[$rag] }} text-[9px] font-black text-base-100" title="{{ $ragLabel[$rag] }}">{{ $ragGlyph[$rag] }}</span>
        </div>
        <div class="mt-3 flex flex-wrap items-baseline gap-x-2">
            <span class="font-display text-4xl font-semibold leading-none tracking-tight tabular-nums">{{ $value }}</span>
        </div>
        <div class="mt-3 flex items-end justify-between gap-2">
            <p class="truncate text-[11px] text-base-content/40">{{ $context }}</p>
        </div>
    </{{ $tag }}>
@endif
