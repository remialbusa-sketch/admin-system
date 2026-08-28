<div class="mx-auto w-full max-w-none space-y-6 pb-4">
    <x-admin.page-header
        eyebrow="Support"
        title="Help center"
        description="Guides and answers for the operations workspace."
    >
        <x-slot name="actions">
            <a href="{{ route('tables') }}" wire:navigate class="admin-secondary-button">
                Open tables
                <x-mary-icon name="o-arrow-right" class="h-4 w-4" />
            </a>
        </x-slot>
    </x-admin.page-header>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(280px,0.8fr)]">
        <section class="admin-surface p-6" aria-label="Frequently asked questions">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold tracking-tight">Frequently asked questions</h2>
                    <p class="mt-1 text-xs text-base-content/50">{{ $faqs === null ? 0 : count($faqs) }} of {{ $faqTotal }} topics shown</p>
                </div>
                <label class="relative flex w-full items-center sm:max-w-xs" aria-label="Search help topics">
                    <x-mary-icon name="o-magnifying-glass" class="pointer-events-none absolute left-3 h-4 w-4 text-base-content/40" />
                    <input wire:model.live.debounce.300ms="search" type="search" class="admin-control w-full pl-9 pr-3 placeholder:text-base-content/35" placeholder="Search topics...">
                </label>
            </div>

            <div class="mt-5 divide-y divide-base-300">
                @forelse ($faqs as $faq)
                    <details class="group py-4 first:pt-0 last:pb-0" @if ($loop->first) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-base-content">
                            {{ $faq['q'] }}
                            <x-mary-icon name="o-chevron-down" class="h-4 w-4 shrink-0 text-base-content/40 transition group-open:rotate-180" />
                        </summary>
                        <p class="mt-2 text-sm leading-6 text-base-content/60">{{ $faq['a'] }}</p>
                    </details>
                @empty
                    <p class="py-8 text-center text-sm text-base-content/45">No topics match <span class="font-semibold text-base-content/70">"{{ $search }}"</span>.</p>
                @endforelse
            </div>
        </section>

        <div class="space-y-5">
            <section class="admin-surface p-6" aria-label="Quick links">
                <h2 class="font-display text-lg font-semibold tracking-tight">Quick links</h2>
                <p class="mt-1 text-xs text-base-content/50">Jump to the most used areas</p>
                <div class="mt-5 space-y-3">
                    @foreach ([
                        ['route' => 'tables', 'label' => 'Tables & imports', 'description' => 'Import and manage source records', 'icon' => 'o-arrow-up-tray'],
                        ['route' => 'technical-service-analysis', 'label' => 'Service analysis', 'description' => 'Completion, workload, brand patterns', 'icon' => 'o-chart-bar-square'],
                        ['route' => 'tsp-analytics', 'label' => 'TSP analytics', 'description' => 'Personnel performance reporting', 'icon' => 'o-users'],
                        ['route' => 'settings', 'label' => 'Settings', 'description' => 'Workspace preferences', 'icon' => 'o-cog-6-tooth'],
                    ] as $link)
                        <a href="{{ route($link['route']) }}" wire:navigate
                           class="flex items-center gap-3 rounded-xl border border-base-300 p-4 transition hover:border-primary/40 hover:bg-base-200/50">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                <x-mary-icon :name="$link['icon']" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-base-content">{{ $link['label'] }}</span>
                                <span class="block truncate text-xs text-base-content/50">{{ $link['description'] }}</span>
                            </span>
                            <x-mary-icon name="o-arrow-right" class="ml-auto h-4 w-4 shrink-0 text-base-content/35" />
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="admin-surface p-6" aria-label="Contact support">
                <h2 class="font-display text-lg font-semibold tracking-tight">Need more help?</h2>
                <p class="mt-2 text-sm leading-6 text-base-content/60">
                    If an import fails or numbers look wrong, check the latest import status on the Tables page first — failed batches show the rows that were skipped.
                </p>
                <p class="mt-3 text-xs text-base-content/45">
                    For anything else, reach out to the workspace Superadmin.
                </p>
            </section>
        </div>
    </div>
</div>