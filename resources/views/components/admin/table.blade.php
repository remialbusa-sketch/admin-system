@props(['caption' => null])

<div {{ $attributes->class(['admin-surface overflow-hidden']) }}>
    @if ($caption)
        <div class="border-b border-base-300 px-4 py-3 text-sm font-semibold text-base-content">{{ $caption }}</div>
    @endif
    <div class="admin-scrollbar overflow-x-auto">
        <table class="data-table w-full min-w-[680px] text-left">
            {{ $slot }}
        </table>
    </div>
</div>
