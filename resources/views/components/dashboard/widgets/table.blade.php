<div class="flex h-full flex-col">
    <x-dashboard.widget-header :tone="$tone" tag="DATA" :title="$label" :subtitle="trim($context) !== '' ? $context : $scope" />

    @if (count($items) === 0)
        <div class="mt-4 flex flex-1 flex-col items-start justify-center rounded-lg border border-dashed border-base-300 p-4">
            <p class="text-sm font-semibold text-base-content/70">No rows in this scope</p>
            <p class="mt-1 text-xs text-base-content/45">The selected dataset is empty — imported records will fill the table.</p>
        </div>
    @else
        <div class="admin-scrollbar mt-4 flex-1 overflow-auto">
            <table class="w-full min-w-[360px] border-separate border-spacing-0 text-left">
                <thead>
                    <tr>
                        <th class="border-b border-base-300 pb-2 pr-3 text-[10px] font-bold uppercase tracking-[0.12em] text-base-content/45">{{ $columns[0]['heading'] ?? '' }}</th>
                        @foreach (array_slice($columns, 1) as $column)
                            <th class="border-b border-base-300 pb-2 pl-2 text-right text-[10px] font-bold uppercase tracking-[0.12em] text-base-content/45">{{ $column['heading'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td class="py-1.5 pr-3 text-sm font-semibold">
                                @if ($item['href'])
                                    <a href="{{ $item['href'] }}" wire:navigate class="underline-offset-2 transition hover:text-primary hover:underline">{{ $item['label'] }}</a>
                                @else
                                    {{ $item['label'] }}
                                @endif
                            </td>
                            @foreach (array_slice($columns, 1) as $column)
                                @php $cell = $item['cells'][$column['key']] ?? null; @endphp
                                <td class="py-1.5 pl-2 text-right text-sm tabular-nums text-base-content/85">
                                    {{ $cell === null ? '—' : number_format($cell, fmod($cell, 1.0) == 0.0 ? 0 : 1) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    @if ($totals !== [])
                        <tr>
                            <td class="border-t border-base-300 pt-2 pr-3 text-xs font-bold uppercase tracking-wider text-base-content/45">Total</td>
                            @foreach (array_slice($columns, 1) as $column)
                                @php $total = $totals[$column['key']] ?? 0; @endphp
                                <td class="border-t border-base-300 pt-2 pl-2 text-right text-sm font-bold tabular-nums">{{ number_format($total, fmod($total, 1.0) == 0.0 ? 0 : 1) }}</td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    @endif
</div>
