@php
    // The sidebar's "Tables" group shows ONLY the tables the current user has
    // pinned on the /tables page (all tables live there). Unpinned tables are
    // reached by opening "Import table" (the Tables page).
    $dataItems = auth()->check()
        ? app(\App\Support\TableCatalog::class)->navForKeys(\App\Models\TablePin::keysFor(auth()->id()))
        : [];

    $analyticsItems = [
        ['route' => 'dashboard', 'label' => 'Home', 'description' => 'Executive command view', 'icon' => 'o-home'],
        ['route' => 'technical-service-analysis', 'label' => 'Technical Service Analysis', 'description' => 'Service & TSP analytics', 'icon' => 'o-chart-bar-square'],
        ['route' => 'tsp-analytics', 'label' => 'TSP Analytics', 'description' => 'Personnel performance', 'icon' => 'o-users'],
    ];

    $utilityItems = [
        ['route' => 'settings', 'label' => 'Settings', 'icon' => 'o-cog-6-tooth'],
        ['route' => 'help-center', 'label' => 'Help center', 'icon' => 'o-question-mark-circle'],
    ];

    // Superadmin-only account administration.
    $adminItems = collect([
        ['route' => 'users', 'label' => 'Users', 'icon' => 'o-user-plus', 'gate' => 'manageUsers'],
    ])->filter(fn ($item) => auth()->user()?->can($item['gate']))->values()->all();
@endphp

<div class="flex min-h-screen shrink-0 lg:min-h-0">
    <div
        x-show="mobileSidebarOpen"
        x-cloak
        x-transition.opacity
        x-on:click="mobileSidebarOpen = false"
        class="fixed inset-0 z-30 bg-neutral/40 lg:hidden"
        aria-hidden="true"
    ></div>

    <aside
        x-cloak
        x-on:click="if (window.matchMedia('(min-width: 1024px)').matches && !$event.target.closest('a, button, form, input, select, textarea')) sidebarCollapsed = !sidebarCollapsed"
        :class="[
            mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            sidebarCollapsed ? 'lg:w-[84px] cursor-pointer' : 'lg:w-64 cursor-pointer'
        ]"
        class="fixed inset-y-0 left-0 z-40 flex h-dvh min-h-screen w-64 -translate-x-full flex-col border-r border-base-300 bg-base-100 transition-[width,transform] duration-200 ease-out lg:sticky lg:inset-y-auto lg:top-0 lg:z-auto lg:h-screen lg:min-h-0 lg:translate-x-0"
    >
        <div class="relative flex h-16 shrink-0 items-center border-b border-base-300" :class="sidebarCollapsed ? 'justify-center px-1.5' : 'px-4'">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex min-w-0 items-center gap-3" :class="sidebarCollapsed ? 'justify-center' : ''" title="Admin System">
                <x-admin.logo class="h-8 w-12" />
                <span x-show="!sidebarCollapsed" x-transition.opacity class="min-w-0 leading-tight">
                    <span class="block truncate text-sm font-bold text-base-content">Admin System</span>
                    <span class="block text-[10px] font-semibold uppercase tracking-[0.16em] text-base-content/45">Operations</span>
                </span>
            </a>

            <span
                x-show="sidebarCollapsed"
                x-transition.opacity
                class="pointer-events-none absolute -right-3.5 top-1/2 z-50 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md border border-base-300 bg-base-100 text-primary"
                aria-hidden="true"
            >
                <x-mary-icon name="o-hand-raised" class="h-4 w-4" />
            </span>

            <button type="button" x-on:click="mobileSidebarOpen = false" class="admin-icon-button absolute right-3 top-1/2 -translate-y-1/2 lg:hidden" aria-label="Close navigation">
                <x-mary-icon name="o-x-mark" class="h-5 w-5" />
            </button>
        </div>

        <nav class="admin-scrollbar flex-1 overflow-y-auto px-3 py-5" aria-label="Primary navigation">
            <p x-show="!sidebarCollapsed" class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.16em] text-base-content/40">Analytics</p>
                        <div class="space-y-1">
                            @foreach ($analyticsItems as $item)
                                @php $active = request()->routeIs($item['route']); @endphp
                                <a
                                    href="{{ route($item['route']) }}"
                                    wire:navigate
                                    @class([
                                        'group flex h-10 items-center rounded-md text-sm font-semibold transition',
                                        'bg-primary text-primary-content' => $active,
                                        'text-base-content/65 hover:bg-base-200 hover:text-base-content' => ! $active,
                                    ])
                                    :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                                    @if($active) aria-current="page" @endif
                                    title="{{ $item['label'] }}"
                                >
                                    <x-mary-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0" />
                                    <span x-show="!sidebarCollapsed" x-transition.opacity class="truncate">{{ $item['label'] }}</span>
                                    <span x-show="!sidebarCollapsed && @js($active)" class="ml-auto h-1.5 w-1.5 rounded-full bg-primary-content/70"></span>
                                </a>
                            @endforeach
                        </div>

                        <div class="my-6 border-t border-base-300"></div>

                        <p x-show="!sidebarCollapsed" class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.16em] text-base-content/40">Tables</p>
                        <div class="space-y-1">
                            @forelse ($dataItems as $item)
                                @php $active = request()->routeIs($item['route']); @endphp
                                <a
                                    href="{{ route($item['route'], $item['params'] ?? []) }}"
                                    wire:navigate
                                    @class([
                                        'group flex h-10 items-center rounded-md text-sm font-semibold transition',
                                        'bg-primary text-primary-content' => $active,
                                        'text-base-content/65 hover:bg-base-200 hover:text-base-content' => ! $active,
                                    ])
                                    :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                                    @if($active) aria-current="page" @endif
                                    title="{{ $item['label'] }}"
                                >
                                    <x-mary-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0" />
                                    <span x-show="!sidebarCollapsed" x-transition.opacity class="truncate">{{ $item['label'] }}</span>
                                    <span x-show="!sidebarCollapsed && @js($active)" class="ml-auto h-1.5 w-1.5 rounded-full bg-primary-content/70"></span>
                                </a>
                            @empty
                                <p x-show="!sidebarCollapsed" class="px-3 pt-1 text-xs leading-5 text-base-content/40">
                                    No pinned tables yet — open a table below to pin it.
                                </p>
                            @endforelse

                            <a
                                href="{{ route('tables') }}"
                                wire:navigate
                                @class([
                                    'group flex h-10 items-center rounded-md text-sm font-semibold transition',
                                    'bg-primary text-primary-content' => request()->routeIs('tables'),
                                    'text-base-content/65 hover:bg-base-200 hover:text-base-content' => ! request()->routeIs('tables'),
                                ])
                                :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                                title="Import table"
                            >
                                <x-mary-icon name="o-arrow-up-tray" class="h-[18px] w-[18px] shrink-0" />
                                <span x-show="!sidebarCollapsed" x-transition.opacity class="truncate">All tables</span>
                            </a>
                        </div>

            <div class="my-6 border-t border-base-300"></div>

            <p x-show="!sidebarCollapsed" class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.16em] text-base-content/40">System</p>
            <div class="space-y-1">
                @foreach ($utilityItems as $item)
                    @php $active = request()->routeIs($item['route']); @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        @class([
                            'group flex h-10 items-center rounded-md text-sm font-semibold transition',
                            'bg-base-200 text-base-content' => $active,
                            'text-base-content/60 hover:bg-base-200 hover:text-base-content' => ! $active,
                        ])
                        :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                        @if($active) aria-current="page" @endif
                        title="{{ $item['label'] }}"
                    >
                        <x-mary-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0" />
                        <span x-show="!sidebarCollapsed" x-transition.opacity class="truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>

            @if (count($adminItems) > 0)
                <div class="my-6 border-t border-base-300"></div>

                <p x-show="!sidebarCollapsed" class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.16em] text-base-content/40">Administration</p>
                <div class="space-y-1">
                    @foreach ($adminItems as $item)
                        @php $active = request()->routeIs($item['route']); @endphp
                        <a
                            href="{{ route($item['route']) }}"
                            wire:navigate
                            @class([
                                'group flex h-10 items-center rounded-md text-sm font-semibold transition',
                                'bg-base-200 text-base-content' => $active,
                                'text-base-content/60 hover:bg-base-200 hover:text-base-content' => ! $active,
                            ])
                            :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                            @if($active) aria-current="page" @endif
                            title="{{ $item['label'] }}"
                        >
                            <x-mary-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0" />
                            <span x-show="!sidebarCollapsed" x-transition.opacity class="truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </nav>

        <footer class="shrink-0 border-t border-base-300 px-3 py-3" :class="sidebarCollapsed ? 'flex justify-center' : ''">
            @php
                // Real import freshness, not a decorative status light — the
                // dashboards are only as current as the last completed import.
                $latestImport = \App\Models\ImportBatch::query()->latest('completed_at')->first();
                $importFailed = ($latestImport?->status) === 'failed';
            @endphp
            <div x-show="!sidebarCollapsed" x-transition.opacity class="flex w-full items-center justify-between gap-2 px-2">
                <span class="flex min-w-0 items-center gap-2 text-[11px] font-semibold {{ $importFailed ? 'text-error' : 'text-base-content/50' }}" title="{{ $latestImport ? 'Last import '.($latestImport->status).($latestImport->completed_at ? ' '.$latestImport->completed_at->diffForHumans() : '') : 'No imports yet' }}">
                    <span class="relative flex h-1.5 w-1.5 shrink-0">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full {{ $importFailed ? 'bg-error/60' : 'bg-success/60' }} opacity-75"></span>
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full {{ $importFailed ? 'bg-error' : 'bg-success' }}"></span>
                    </span>
                    <span class="truncate">{{ $latestImport?->completed_at?->diffForHumans() ?? 'No imports yet' }}</span>
                </span>
                <span class="text-[10px] font-bold uppercase tracking-[0.14em] text-base-content/35">v{{ app()->version() }}</span>
            </div>
            <span x-show="sidebarCollapsed" x-transition.opacity class="relative flex h-1.5 w-1.5" title="{{ $latestImport ? 'Last import '.($latestImport->status).($latestImport->completed_at ? ' '.$latestImport->completed_at->diffForHumans() : '') : 'No imports yet' }}">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full {{ $importFailed ? 'bg-error/60' : 'bg-success/60' }} opacity-75"></span>
                <span class="relative inline-flex h-1.5 w-1.5 rounded-full {{ $importFailed ? 'bg-error' : 'bg-success' }}"></span>
            </span>
        </footer>
    </aside>
</div>
