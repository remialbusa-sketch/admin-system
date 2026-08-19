<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex h-9 items-center justify-center gap-2 rounded-md bg-error px-3.5 text-sm font-semibold text-error-content transition hover:bg-error/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-error/30 disabled:cursor-not-allowed disabled:opacity-50']) }}>
    {{ $slot }}
</button>
