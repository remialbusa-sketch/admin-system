<div {{ $attributes->class(['admin-surface flex flex-wrap items-center gap-3 p-3']) }}>
    <div class="flex items-center gap-2 pr-2 text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">
        <x-mary-icon name="o-adjustments-horizontal" class="h-4 w-4" />
        <span>Filters</span>
    </div>
    {{ $slot }}
</div>
