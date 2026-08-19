@php
    $metrics = [
        ['label' => 'Open records', 'value' => '1,284', 'change' => '+8.4%', 'context' => 'vs. previous period', 'tone' => 'success', 'icon' => 'o-inbox-stack'],
        ['label' => 'SLA at risk', 'value' => '37', 'change' => '-12.1%', 'context' => 'vs. previous period', 'tone' => 'success', 'icon' => 'o-shield-exclamation'],
        ['label' => 'Active TSPs', 'value' => '248', 'change' => '+4.6%', 'context' => 'vs. previous period', 'tone' => 'success', 'icon' => 'o-users'],
        ['label' => 'Imported this week', 'value' => '3,892', 'change' => '+18.9%', 'context' => 'new records', 'tone' => 'info', 'icon' => 'o-arrow-up-tray'],
    ];

    $statusSummary = [
        ['label' => 'New', 'count' => '312', 'share' => '24.3%', 'tone' => 'info'],
        ['label' => 'In progress', 'count' => '521', 'share' => '40.6%', 'tone' => 'warning'],
        ['label' => 'Blocked', 'count' => '37', 'share' => '2.9%', 'tone' => 'danger'],
        ['label' => 'Resolved', 'count' => '414', 'share' => '32.2%', 'tone' => 'success'],
    ];

    $trend = [
        ['day' => 'Mon', 'value' => 58],
        ['day' => 'Tue', 'value' => 74],
        ['day' => 'Wed', 'value' => 62],
        ['day' => 'Thu', 'value' => 88],
        ['day' => 'Fri', 'value' => 79],
        ['day' => 'Sat', 'value' => 46],
        ['day' => 'Sun', 'value' => 34],
    ];

    $attention = [
        ['title' => '37 records are nearing SLA', 'detail' => 'Prioritize before 18:00 today', 'tone' => 'danger', 'icon' => 'o-clock'],
        ['title' => 'North Luzon coverage is low', 'detail' => '8 open records without an assignee', 'tone' => 'warning', 'icon' => 'o-map-pin'],
        ['title' => 'Latest import completed', 'detail' => '1,104 records added at 09:12', 'tone' => 'success', 'icon' => 'o-check-circle'],
    ];

    $activity = [
        ['record' => 'SR-1048', 'event' => 'Status changed to In progress', 'actor' => 'A. Reyes', 'time' => '09:42'],
        ['record' => 'SR-1047', 'event' => 'Imported from service-log.xlsx', 'actor' => 'System', 'time' => '09:12'],
        ['record' => 'SR-1042', 'event' => 'Label "Escalated" added', 'actor' => 'M. Santos', 'time' => '08:55'],
        ['record' => 'SR-1039', 'event' => 'Marked as Resolved', 'actor' => 'J. Dela Cruz', 'time' => '08:31'],
    ];
@endphp

<div class="space-y-5">
    <x-admin.page-header
        eyebrow="Overview"
        title="Home"
        description="A focused view of record flow, service coverage, and work that needs attention today."
    >
        <x-slot name="actions">
            <a href="{{ route('records') }}" wire:navigate class="admin-secondary-button">
                View records
                <x-mary-icon name="o-arrow-right" class="h-4 w-4" />
            </a>
            <a href="{{ route('tsp-analytics') }}" wire:navigate class="admin-primary-button">Open TSP analytics</a>
        </x-slot>
    </x-admin.page-header>

    <section class="admin-surface grid grid-cols-1 divide-y divide-base-300 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-4" aria-label="Key metrics">
        @foreach ($metrics as $metric)
            <div class="flex items-start justify-between gap-4 p-5">
                <div>
                    <p class="text-xs font-semibold text-base-content/55">{{ $metric['label'] }}</p>
                    <p class="metric-value mt-2 text-3xl font-semibold text-base-content">{{ $metric['value'] }}</p>
                    <p class="mt-2 text-[11px] text-base-content/45">
                        <span class="font-bold {{ $metric['tone'] === 'success' ? 'text-success' : 'text-info' }}">{{ $metric['change'] }}</span>
                        {{ $metric['context'] }}
                    </p>
                </div>
                <span class="flex h-8 w-8 items-center justify-center rounded-md bg-base-200 text-base-content/55">
                    <x-mary-icon :name="$metric['icon']" class="h-4 w-4" />
                </span>
            </div>
        @endforeach
    </section>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.55fr)_minmax(320px,0.85fr)]">
        <section class="admin-surface p-5 sm:p-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-base font-bold text-base-content">Record intake</h2>
                    <p class="mt-1 text-xs text-base-content/55">Records processed over the last seven days</p>
                </div>
                <div class="flex items-center gap-4 text-[11px] font-semibold text-base-content/50">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary"></span>Processed</span>
                    <span class="tabular-nums">3,892 total</span>
                </div>
            </div>

            <div class="mt-8 flex h-52 items-end gap-3 border-b border-l border-base-300 px-4 pb-0 pt-4 sm:gap-5" aria-label="Seven-day record intake chart">
                @foreach ($trend as $point)
                    <div class="flex h-full flex-1 flex-col items-center justify-end gap-2">
                        <span class="tabular-nums text-[10px] font-bold text-base-content/45">{{ $point['value'] * 10 }}</span>
                        <div class="w-full max-w-12 bg-primary/75 transition hover:bg-primary" style="height: {{ $point['value'] }}%" title="{{ $point['value'] * 10 }} records"></div>
                        <span class="text-[10px] font-bold text-base-content/45">{{ $point['day'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="admin-surface p-5 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-base-content">Attention needed</h2>
                    <p class="mt-1 text-xs text-base-content/55">Signals that may need an operator</p>
                </div>
                <x-mary-icon name="o-ellipsis-horizontal" class="h-5 w-5 text-base-content/35" />
            </div>

            <div class="mt-5 divide-y divide-base-300">
                @foreach ($attention as $item)
                    @php
                        $attentionToneClasses = [
                            'danger' => 'bg-error/10 text-error',
                            'warning' => 'bg-warning/15 text-warning-content',
                            'success' => 'bg-success/10 text-success',
                        ][$item['tone']] ?? 'bg-base-200 text-base-content/60';
                    @endphp
                    <div class="flex gap-3 py-4 first:pt-0 last:pb-0">
                        <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md {{ $attentionToneClasses }}">
                            <x-mary-icon :name="$item['icon']" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-base-content">{{ $item['title'] }}</p>
                            <p class="mt-1 text-xs leading-5 text-base-content/55">{{ $item['detail'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1.4fr)]">
        <x-admin.table caption="Work by status">
            <thead>
                <tr>
                    <th>Status</th>
                    <th class="text-right">Records</th>
                    <th class="text-right">Share</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statusSummary as $row)
                    <tr>
                        <td><x-admin.badge :tone="$row['tone']">{{ $row['label'] }}</x-admin.badge></td>
                        <td class="tabular-nums text-right font-semibold text-base-content">{{ $row['count'] }}</td>
                        <td class="tabular-nums text-right text-base-content/55">{{ $row['share'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-admin.table>

        <x-admin.table caption="Recent activity">
            <thead>
                <tr>
                    <th>Record</th>
                    <th>Activity</th>
                    <th>Actor</th>
                    <th class="text-right">Time</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($activity as $item)
                    <tr>
                        <td class="font-bold text-primary">{{ $item['record'] }}</td>
                        <td class="text-base-content/70">{{ $item['event'] }}</td>
                        <td class="text-base-content/55">{{ $item['actor'] }}</td>
                        <td class="tabular-nums text-right text-base-content/45">{{ $item['time'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-admin.table>
    </div>
</div>
