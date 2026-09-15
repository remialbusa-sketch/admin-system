<div class="flex h-full flex-col">
    <div class="flex items-start justify-between gap-3">
        <x-dashboard.widget-header :tone="$tone" tag="SLA" :title="$label" :subtitle="trim($context) !== '' ? $context : $scope" />
        <span class="shrink-0 rounded-full bg-warning/15 px-2.5 py-1 text-[11px] font-bold text-warning-content" title="Commitments with an end date inside the 90-day window">
            {{ number_format($expiring) }} {{ $metricLabel }}
        </span>
    </div>

    @forelse ($items as $item)
        <div class="mt-2.5 flex items-center gap-3 rounded-md border border-base-300 bg-base-100 px-3 py-2.5 first:mt-4">
            <span class="h-2 w-2 shrink-0 rounded-full {{ $item['overdue'] ? 'bg-error' : 'bg-warning' }}" aria-hidden="true"></span>
            <div class="min-w-0 flex-1">
                @if ($item['href'])
                    <a href="{{ $item['href'] }}" wire:navigate class="block truncate text-sm font-semibold underline-offset-2 transition hover:text-primary hover:underline" title="{{ $item['label'] }}">{{ $item['label'] }}</a>
                @else
                    <p class="truncate text-sm font-semibold" title="{{ $item['label'] }}">{{ $item['label'] }}</p>
                @endif
                <p class="text-[11px] text-base-content/45">Ends {{ $item['due']->format('M j, Y') }}</p>
            </div>
            {{-- Server renders the static fallback; Alpine ticks the live
                d/h/m/s breakdown once per second. --}}
            <span class="shrink-0 rounded-md bg-base-200 px-2 py-1 text-xs font-bold tabular-nums text-base-content/80"
                  x-data="{ now: Date.now(), due: new Date('{{ $item['dueIso'] }}').getTime() }"
                  x-init="const timer = setInterval(() => { now = Date.now(); if (now >= due) { clearInterval(timer) } }, 1000)"
                  x-text="due > now ? Math.floor((due - now) / 86400000) + 'd ' + Math.floor((due - now) % 86400000 / 3600000) + 'h ' + Math.floor((due - now) % 3600000 / 60000) + 'm ' + Math.floor((due - now) % 60000 / 1000) + 's' : 'Closed'"
            >{{ $item['overdue'] ? 'Closed' : $item['d'].'d '.$item['h'].'h' }}</span>
        </div>
    @empty
        <div class="mt-4 flex flex-1 flex-col items-start justify-center rounded-lg border border-dashed border-base-300 p-4">
            <p class="text-sm font-semibold text-base-content/70">The 90-day window is clear</p>
            <p class="mt-1 text-xs text-base-content/45">No warranty end dates fall inside the countdown window in this scope — new imports will populate it.</p>
        </div>
    @endforelse
</div>
