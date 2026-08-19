@props(['tone' => 'neutral'])

@php
    $toneClasses = match ($tone) {
        'success' => 'bg-success/12 text-success',
        'warning' => 'bg-warning/18 text-warning-content',
        'danger', 'error' => 'bg-error/12 text-error',
        'info' => 'bg-info/12 text-info',
        'primary' => 'bg-primary/12 text-primary',
        default => 'bg-base-200 text-base-content/65',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap rounded-full px-2 py-1 text-[11px] font-bold leading-none', $toneClasses]) }}>
    {{ $slot }}
</span>
