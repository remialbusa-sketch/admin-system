<div class="flex min-h-0 flex-col" style="height: min({{ $contentHeight }}px, 100%)" x-data="{ search: '' }">
    <div class="flex items-start justify-between gap-2">
        <div>
            <h3 class="text-sm font-bold text-base-content">{{ $label }}</h3>
            <p class="mt-1 text-[11px] text-base-content/50">{{ $multiple ? 'Select one or more' : 'Select one' }} · filters this table</p>
        </div>
        @if ($selected && $slicerId !== '__preview')
            <button type="button" wire:click="clearSlicer(@js($slicerId))" class="text-xs font-semibold text-primary">Clear</button>
        @endif
    </div>
    @if (! $configured)
        <p class="mt-4 text-sm text-base-content/50">Choose a connected table’s column in Widget settings.</p>
    @else
        <input type="search" x-model="search" aria-label="Search {{ $label }}" placeholder="Search values…" class="admin-control mt-3 w-full text-xs">
        <div class="mt-3 min-h-0 flex-1 overflow-auto" wire:loading.class="opacity-50">
            <div class="grid gap-1.5" style="grid-template-columns: repeat({{ $buttonColumns }}, minmax(0, 1fr))" role="group" aria-label="{{ $label }}">
                @foreach ($options as $option)
                    @php $isSelected = in_array($option['value'], $selected, true); @endphp
                    <button type="button" x-show="@js(mb_strtolower($option['label'])).includes(search.toLowerCase())"
                        @if ($slicerId !== '__preview') wire:click="toggleSlicerValue(@js($slicerId), @js($option['value']))" @endif
                        aria-pressed="{{ $isSelected ? 'true' : 'false' }}" title="{{ $option['label'] }}: {{ number_format($option['count']) }} records"
                        class="min-w-0 rounded-md border px-2 py-2 text-left text-xs transition {{ $isSelected ? 'border-primary bg-primary/15 font-semibold text-primary' : 'border-base-300 bg-base-200/40 text-base-content hover:border-primary/50' }}">
                        <span class="block break-words">{{ $option['label'] }}</span>
                        <span class="mt-0.5 block text-[10px] opacity-55">{{ number_format($option['count']) }}</span>
                    </button>
                @endforeach
            </div>
            @if (! $options)<p class="py-4 text-xs text-base-content/50">No values match the current filters.</p>@endif
        </div>
        @if ($truncated)<p class="mt-2 text-[11px] text-warning">Showing the first 500 values. Narrow the other filters to see more.</p>@endif
    @endif
</div>
