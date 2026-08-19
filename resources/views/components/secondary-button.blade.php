<button {{ $attributes->merge(['type' => 'button', 'class' => 'admin-secondary-button']) }}>
    {{ $slot }}
</button>
