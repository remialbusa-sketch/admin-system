<div class="fi-widget-column-span-full">
    <div class="rounded-xl border border-[var(--gray-200)] bg-[var(--gray-50)] p-4 dark:border-[var(--gray-700)] dark:bg-[var(--gray-900)]">
        <p class="text-sm font-bold text-[var(--gray-950)] dark:text-white">Workspace shortcuts</p>
        <p class="mt-0.5 text-xs text-[var(--gray-500)] dark:text-[var(--gray-400)]">Jump into the main operations workspace.</p>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            @foreach ($links as $link)
                <a
                    href="{{ $link['url'] }}"
                    class="group flex items-start gap-3 rounded-lg border border-[var(--gray-200)] bg-white p-3 transition hover:border-[var(--primary-500)] dark:border-[var(--gray-700)] dark:bg-[var(--gray-800)]"
                >
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-[var(--primary-600)] text-[var(--primary-50)]">
                        <x-dynamic-component :component="$link['icon']" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-[var(--gray-950)] group-hover:text-[var(--primary-600)] dark:text-white">{{ $link['title'] }}</span>
                        <span class="mt-0.5 block text-xs leading-4 text-[var(--gray-500)] dark:text-[var(--gray-400)]">{{ $link['description'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</div>
