@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'admin-control bg-base-100 text-base-content']) }}>
