@php
    $ragBg = ['green' => 'bg-success', 'amber' => 'bg-warning', 'red' => 'bg-error'];
    $ragText = ['green' => 'text-success', 'amber' => 'text-warning', 'red' => 'text-error'];
    $ragGlyph = ['green' => '✓', 'amber' => '!', 'red' => '✕'];
    $ragLabel = ['green' => 'on track', 'amber' => 'watch', 'red' => 'critical'];
    $rag = in_array($rag ?? 'green', ['green', 'amber', 'red'], true) ? $rag : 'green';
@endphp
@if (($variant ?? 'card') === 'lead')
    <div class="flex h-full flex-col">
        <div class="flex h-full flex-col">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm font-semibold text-base-content/75">{{ $label }}</p>
                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full {{ $ragBg[$rag] }} text-[10px] font-black text-base-100" title="{{ $ragLabel[$rag] }}">{{ $ragGlyph[$rag] }}</span>
            </div>
            <p class="mt-6 font-display text-6xl font-semibold leading-none tracking-tight tabular-nums text-base-content">{{ $value }}</p>
            @if (($caption ?? '') !== '')
                <p class="mt-2 text-xs text-base-content/70">{{ $caption }}</p>
            @endif
            <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-base-300 pt-4">
                @if (($context ?? '') !== '')
                    <span class="text-xs text-base-content/70">{{ $context }}</span>
                @endif
                @if (($href ?? null) !== null)
                    <a href="{{ $href }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-primary">Open table <x-mary-icon name="o-arrow-right" class="h-3.5 w-3.5" /></a>
                @endif
            </div>
        </div>
    </div>
@else
    @php $tag = ($href ?? null) !== null ? 'a' : 'div'; @endphp
    <{{ $tag }} @if (($href ?? null) !== null) href="{{ $href }}" wire:navigate @endif
         class="flex h-full flex-col justify-between rounded-sm {{ ($href ?? null) !== null ? 'cursor-pointer transition-colors hover:bg-base-200/40 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary' : '' }}">
        <div class="flex items-start justify-between gap-3">
            <p class="min-w-0 text-sm font-semibold leading-5 text-base-content/75">{{ $label }}</p>
            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full {{ $ragBg[$rag] }} text-[9px] font-black text-base-100" title="{{ $ragLabel[$rag] }}">{{ $ragGlyph[$rag] }}</span>
        </div>
        <div class="mt-3 flex flex-wrap items-baseline gap-x-2">
            <span class="font-display text-4xl font-semibold leading-none tracking-tight tabular-nums">{{ $value }}</span>
        </div>
        <div class="mt-3 flex items-end justify-between gap-2">
            <p class="text-xs leading-5 text-base-content/70">{{ $context }}</p>
        </div>
    </{{ $tag }}>
@endif
