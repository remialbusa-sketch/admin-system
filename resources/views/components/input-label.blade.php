@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55']) }}>
    {{ $value ?? $slot }}
</label>
