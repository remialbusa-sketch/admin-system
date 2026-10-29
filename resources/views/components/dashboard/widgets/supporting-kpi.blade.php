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
     class="group flex h-full items-center gap-3 rounded-sm {{ ($href ?? null) !== null ? 'cursor-pointer transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary' : '' }}">
    @if (($icon ?? '') !== '')
        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md {{ $toneTiles[$tone] ?? $toneTiles['primary'] }}">
            <x-mary-icon :name="$icon" class="h-4 w-4" />
        </span>
    @else
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $ragBg[$rag] }}/15 text-xs font-bold {{ $ragText[$rag] }}" title="{{ $ragLabel[$rag] }}">{{ $ragGlyph[$rag] }}</span>
    @endif
    <div class="min-w-0 flex-1">
        <p class="text-xs font-semibold leading-5 text-base-content/75">{{ $label }}</p>
        <p class="mt-1 font-display text-2xl font-semibold leading-none tabular-nums">{{ $value }}</p>
        <p class="mt-1 text-xs leading-5 text-base-content/70">{{ $context }}</p>
    </div>
    @if (($href ?? null) !== null && ($icon ?? '') === '')
        <x-mary-icon name="o-arrow-right" class="ml-auto h-3.5 w-3.5 shrink-0 text-primary opacity-0 transition-opacity duration-200 group-hover:opacity-100" />
    @endif
</{{ $tag }}>
