<div class="flex h-full flex-col">
    <x-dashboard.widget-header :tone="$tone" tag="HEATMAP" :title="$label" :subtitle="trim($context) !== '' ? $context : $scope" />
    <div class="admin-scrollbar mt-5 flex-1 overflow-x-auto">
        <table class="w-full min-w-[440px] border-separate border-spacing-0 text-left">
            <thead>
                <tr>
                    <th class="border-b border-base-300 pb-2 pr-3 text-[10px] font-bold uppercase tracking-[0.12em] text-base-content/45">Region</th>
                    @foreach ($columns as $column)
                        <th class="border-b border-base-300 pb-2 pl-1.5 text-right text-[10px] font-bold uppercase tracking-[0.12em] text-base-content/45">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="py-1.5 pr-3 text-sm font-semibold">
                            @if ($row['href'])
                                <a href="{{ $row['href'] }}" wire:navigate class="underline-offset-2 transition hover:text-primary hover:underline">{{ $row['label'] }}</a>
                            @else
                                {{ $row['label'] }}
                            @endif
                        </td>
                        @foreach ($row['cells'] as $cell)
                            @php
                                $fill = match ($cell['tone']) {
                                    'success' => 'var(--color-success)',
                                    'warning' => 'var(--color-warning)',
                                    'error' => 'var(--color-error)',
                                    default => 'var(--color-primary)',
                                };
                                $decimals = $cell['value'] !== null && floor($cell['value']) == $cell['value'] ? 0 : 1;
                            @endphp
                            <td class="py-1.5 pl-1.5 text-right">
                                @if ($cell['value'] === null)
                                    <span class="inline-block min-w-[4.5rem] rounded-md bg-base-200/60 px-2.5 py-1.5 text-sm text-base-content/30">—</span>
                                @else
                                    <span class="inline-block min-w-[4.5rem] rounded-md px-2.5 py-1.5 text-sm font-semibold tabular-nums text-base-content"
                                          style="background: color-mix(in oklab, {{ $fill }} {{ $cell['intensity'] }}%, transparent)"
                                          title="{{ $cell['value'] }}">{{ number_format($cell['value'], $decimals) }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 1 }}" class="py-6 text-center text-sm text-base-content/45">No rows in this scope.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
