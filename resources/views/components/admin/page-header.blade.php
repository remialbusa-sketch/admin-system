@props([
    'eyebrow' => null,
    'title',
    'description' => null,
])

<header class="admin-page-header">
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="admin-eyebrow mb-2">{{ $eyebrow }}</p>
        @endif
        <h1 class="admin-page-title">{{ $title }}</h1>
        @if ($description)
            <p class="admin-page-description">{{ $description }}</p>
        @endif
    </div>

    @if (isset($actions))
        <div class="admin-page-actions">
            {{ $actions }}
        </div>
    @endif
</header>
