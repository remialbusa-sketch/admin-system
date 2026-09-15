{{-- Degraded state for a widget whose configuration is broken (bad
    expression, unknown dataset). One widget failing never takes the
    dashboard down — this card explains itself instead. --}}
<div class="flex h-full flex-col items-start justify-center gap-2">
    <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-error">
        <span class="inline-block h-px w-5 bg-error/50"></span>Widget config error
    </p>
    <p class="text-sm text-base-content/70">This widget could not render: {{ $message }}</p>
    <p class="text-[11px] text-base-content/40">Check the expression and props for the '{{ $widget }}' widget — other widgets are unaffected.</p>
</div>
