<button {{ $attributes->merge(['type' => 'submit', 'class' => 'admin-primary-button']) }}>
    {{ $slot }}
</button>
