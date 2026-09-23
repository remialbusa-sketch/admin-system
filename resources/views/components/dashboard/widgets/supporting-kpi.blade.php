@php
    $ragBg = ['green' => 'bg-success', 'amber' => 'bg-warning', 'red' => 'bg-error'];
    $ragText = ['green' => 'text-success', 'amber' => 'text-warning', 'red' => 'text-error'];
    $ragGlyph = ['green' => '✓', 'amber' => '!', 'red' => '✕'];
    $ragLabel = ['green' => 'on track', 'amber' => 'watch', 'red' => 'critical'];
    $rag = in_array($rag ?? 'green', ['green', 'amber', 'red'], true) ? $rag : 'green';
    $toneTiles = [
        'primary' => 'bg-primary/10 text-primary',
        'info' => 'bg-info/10 text-info',
        'success' => 'bg-success/10 text-success',
        'warning' => 'bg-warning/15 text-warning-content',
    ];
    $tag = ($href ?? null) !== null ? 'a' : 'div';
@endphp
<{{ $tag }} @if (($href ?? null) !== null) href="{{ $href }}" wire:navigate @endif
     class="group flex h-full items-center gap-4 {{ ($href ?? null) !== null ? 'cursor-pointer transition hover:bg-base-200/50' : '' }}">
    @if (($icon ?? '') !== '')
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md {{ $toneTiles[$tone] ?? $toneTiles['primary'] }}">
            <x-mary-icon :name="$icon" class="h-4 w-4" />
        </span>
    @else
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $ragBg[$rag] }}/15 text-sm font-black {{ $ragText[$rag] }}" aria-hidden="true">{{ $ragGlyph[$rag] }}</div>
    @endif
    <div class="min-w-0 flex-1">
        <p class="truncate text-[11px] font-bold uppercase tracking-[0.12em] text-base-content/45">{{ $label }}</p>
        <p class="mt-1 font-display text-xl font-semibold leading-none tabular-nums">{{ $value }}</p>
        <p class="mt-1 truncate text-[10px] text-base-content/35">{{ $context }}</p>
    </div>
    @if (($href ?? null) !== null && ($icon ?? '') === '')
        <x-mary-icon name="o-arrow-right" class="ml-auto h-3.5 w-3.5 shrink-0 text-primary opacity-0 transition-opacity duration-200 group-hover:opacity-100" />
    @endif
</{{ $tag }}>
