@php
    $barTone = $state === 'met' ? 'success' : ($state === 'approaching' ? 'warning' : 'error');
    $stateLabels = ['met' => 'goal met', 'approaching' => 'approaching', 'behind' => 'behind'];
    $decimals = fmod($current, 1.0) == 0.0 && fmod($goal, 1.0) == 0.0 ? 0 : 1;
@endphp
<div class="flex h-full flex-col">
    <x-dashboard.widget-header :tone="$tone" tag="GOAL" :title="$label" :subtitle="trim($context) !== '' ? $context : $scope" />
    <div class="mt-auto pt-5">
        <div class="flex items-end justify-between gap-3">
            <p class="metric-value text-4xl font-semibold leading-none tracking-tight">
                {{ number_format($current, $decimals) }}<span class="text-xl text-base-content/55">{{ $unit }}</span>
                <span class="ml-1 text-base font-normal text-base-content/40">/ {{ number_format($goal, $decimals) }}{{ $unit }}</span>
            </p>
            <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold {{ $state === 'met' ? 'bg-success/15 text-success' : ($state === 'approaching' ? 'bg-warning/15 text-warning-content' : 'bg-error/15 text-error') }}">
                {{ $stateLabels[$state] ?? $state }} · {{ number_format($percent, 0) }}%
            </span>
        </div>
        <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-base-200" role="progressbar" aria-valuenow="{{ (int) $percent }}" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full transition-all duration-500" style="width: {{ $percent }}%; background: var(--color-{{ $barTone }})"></div>
        </div>
    </div>
</div>
