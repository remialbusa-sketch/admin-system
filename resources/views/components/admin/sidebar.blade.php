@php
    // The sidebar's "Tables" group lists ONLY the tables the current user
    // has pinned on the /tables page — core tables follow pin/unpin exactly
    // like user-created ones (unpinned tables live behind "All tables" below).
    // Pins to tables the user can no longer open (share revoked, ownerless
    // table) are dropped instead of rendering a dead link.
    $sidebarUser = auth()->user();
    $sidebarCatalog = app(\App\Support\TableCatalog::class);

    $sidebarKeys = [];

    if ($sidebarUser !== null) {
        $coreKeys = array_keys(\App\Support\TableCatalog::CORE);
        $openableKeys = array_merge(
            $coreKeys,
            \App\Models\DynamicTable::query()->visibleTo($sidebarUser)->pluck('key')->all(),
        );

        $sidebarKeys = array_values(array_intersect(
            \App\Models\TablePin::keysFor($sidebarUser->id),
            $openableKeys,
        ));
    }

    $dataItems = $sidebarCatalog->navForKeys($sidebarKeys);

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
        id="admin-navigation"
        x-cloak
        x-trap.inert.noscroll="mobileSidebarOpen"
        :class="[
            mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            sidebarCollapsed ? 'lg:w-[76px]' : 'lg:w-64'
        ]"
        class="admin-sidebar fixed inset-y-0 left-0 z-40 flex h-dvh min-h-screen w-64 -translate-x-full flex-col border-r border-base-300 bg-base-100 transition-[width,transform] duration-200 ease-out lg:sticky lg:inset-y-auto lg:top-0 lg:z-auto lg:h-screen lg:min-h-0 lg:translate-x-0"
    >
        <div class="relative flex h-16 shrink-0 items-center border-b border-base-300" :class="sidebarCollapsed ? 'justify-center px-1.5' : 'px-4'">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex min-w-0 items-center gap-3" :class="sidebarCollapsed ? 'justify-center' : ''" title="Admin System">
                <x-admin.logo class="h-8 w-12" />
                <span x-show="!sidebarCollapsed" x-transition.opacity class="min-w-0 leading-tight">
                    <span class="block truncate text-sm font-bold text-base-content">Admin System</span>
                    <span class="mt-1 block text-[10px] font-medium uppercase tracking-[0.14em] text-base-content/65">MCBTSi Operations</span>
                </span>
            </a>

            <button type="button" x-on:click="mobileSidebarOpen = false" class="admin-icon-button absolute right-3 top-1/2 -translate-y-1/2 lg:hidden" aria-label="Close navigation">
                <x-mary-icon name="o-x-mark" class="h-5 w-5" />
            </button>
        </div>

        <nav class="admin-scrollbar flex-1 overflow-y-auto px-3 py-5" aria-label="Primary navigation" x-on:click="if ($event.target.closest('a')) mobileSidebarOpen = false">
            <p x-show="!sidebarCollapsed" class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-base-content/60">Analytics</p>
                        <div class="space-y-1">
                            @foreach ($analyticsItems as $item)
                                @php $active = request()->routeIs($item['route']); @endphp
                                <a
                                    href="{{ route($item['route']) }}"
                                    wire:navigate
                                    data-sidebar-link
                                    data-active="admin-nav-active"
                                    data-inactive="admin-nav-inactive"
                                    @class([
                                        'group flex min-h-10 items-center rounded-md py-2 text-sm font-semibold transition',
                                        'admin-nav-active' => $active,
                                        'admin-nav-inactive' => ! $active,
                                    ])
                                    :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                                    @if($active) aria-current="page" @endif
                                    title="{{ $item['label'] }}"
                                >
                                    <x-mary-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0" />
                                    <span x-show="!sidebarCollapsed" x-transition.opacity class="min-w-0 whitespace-normal leading-5">{{ $item['label'] }}</span>
                                    <span data-sidebar-indicator x-show="!sidebarCollapsed" @class(['ml-auto h-1.5 w-1.5 rounded-full bg-primary-content/70', 'hidden' => !$active])></span>
                                </a>
                            @endforeach

                            {{-- User dashboards: the index plus the dashboards this
                                 user owns or has been given access to, so a newly
                                 created dashboard shows up here immediately. --}}
                            @php
                                $dashboardsActive = request()->routeIs('dashboards.*') || request()->routeIs('visualize');
                            @endphp
                            <a
                                href="{{ route('dashboards.index') }}"
                                wire:navigate
                                data-sidebar-link
                                data-sidebar-prefix
                                data-active="admin-nav-active"
                                data-inactive="admin-nav-inactive"
                                @class([
                                    'group flex min-h-10 items-center rounded-md py-2 text-sm font-semibold transition',
                                    'admin-nav-active' => $dashboardsActive,
                                    'admin-nav-inactive' => ! $dashboardsActive,
                                ])
                                :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                                @if($dashboardsActive) aria-current="page" @endif
                                title="Dashboards"
                            >
                                <x-mary-icon name="o-squares-2x2" class="h-[18px] w-[18px] shrink-0" />
                                <span x-show="!sidebarCollapsed" x-transition.opacity class="min-w-0 whitespace-normal leading-5">Dashboards</span>
                                <span x-show="!sidebarCollapsed" class="ml-auto rounded-full bg-base-200 px-1.5 text-[10px] font-bold tabular-nums text-base-content/50">{{ $dashboards->count() }}</span>
                            </a>

                            <div x-show="!sidebarCollapsed" x-transition.opacity class="space-y-0.5 pl-3">
                                @forelse ($dashboards as $dashboard)
                                    @php
                                        // Route model binding hands back the Dashboard model,
                                        // not an id — never cast it to int.
                                        $routeDashboard = request()->route('dashboard');
                                        $routeDashboardId = $routeDashboard instanceof \App\Models\Dashboard
                                            ? $routeDashboard->id
                                            : (is_numeric($routeDashboard) ? (int) $routeDashboard : null);
                                        $dashboardActive = request()->routeIs('dashboards.show')
                                            && $routeDashboardId === $dashboard->id;
                                    @endphp
                                    <a
                                        href="{{ route('dashboards.show', $dashboard) }}"
                                        wire:navigate
                                        data-sidebar-link
                                        data-active="bg-primary/10 text-primary font-semibold"
                                        data-inactive="text-base-content/55 hover:bg-base-200 hover:text-base-content"
                                        @class([
                                            'group flex h-8 items-center gap-2 rounded-md pr-3 text-xs font-medium transition',
                                            'bg-primary/10 text-primary font-semibold' => $dashboardActive,
                                            'text-base-content/55 hover:bg-base-200 hover:text-base-content' => ! $dashboardActive,
                                        ])
                                        @if($dashboardActive) aria-current="page" @endif
                                        title="{{ $dashboard->name }}"
                                    >
                                        <span data-sidebar-subdot class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dashboardActive ? 'bg-primary' : 'bg-base-content/25' }}"></span>
                                        <span class="truncate">{{ $dashboard->name }}</span>
                                        @if ($dashboard->is_system)
                                            <span class="ml-auto text-[9px] font-bold uppercase tracking-[0.08em] text-base-content/35">Tpl</span>
                                        @endif
                                    </a>
                                @empty
                                    <p class="px-3 pb-1 pt-0.5 text-[11px] leading-4 text-base-content/60">No dashboards yet — create one from the Dashboards page.</p>
                                @endforelse
                            </div>
                        </div>

                        <div class="my-4 border-t border-base-300"></div>

                        <p x-show="!sidebarCollapsed" class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-base-content/60">Tables</p>
                        <div class="space-y-1">
                            @forelse ($dataItems as $item)
                                @php
                                    $routeTable = request()->route('table');
                                    $active = request()->routeIs($item['route'])
                                        && (!isset($item['params']['table']) || (string) $routeTable === (string) $item['params']['table']);
                                @endphp
                                <a
                                    href="{{ route($item['route'], $item['params'] ?? []) }}"
                                    wire:navigate
                                    data-sidebar-link
                                    data-active="admin-nav-active"
                                    data-inactive="admin-nav-inactive"
                                    @class([
                                        'group flex min-h-10 items-center rounded-md py-2 text-sm font-semibold transition',
                                        'admin-nav-active' => $active,
                                        'admin-nav-inactive' => ! $active,
                                    ])
                                    :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                                    @if($active) aria-current="page" @endif
                                    title="{{ $item['label'] }}"
                                >
                                    <x-mary-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0" />
                                    <span x-show="!sidebarCollapsed" x-transition.opacity class="min-w-0 whitespace-normal leading-5">{{ $item['label'] }}</span>
                                    <span data-sidebar-indicator x-show="!sidebarCollapsed" @class(['ml-auto h-1.5 w-1.5 rounded-full bg-primary-content/70', 'hidden' => !$active])></span>
                                </a>
                            @empty
                                <p x-show="!sidebarCollapsed" class="px-3 pt-1 text-xs leading-5 text-base-content/60">
                                    No pinned tables yet — open a table below to pin it.
                                </p>
                            @endforelse

                            <a
                                href="{{ route('tables') }}"
                                wire:navigate
                                data-sidebar-link
                                data-active="admin-nav-active"
                                data-inactive="admin-nav-inactive"
                                @class([
                                    'group flex min-h-10 items-center rounded-md py-2 text-sm font-semibold transition',
                                    'admin-nav-active' => request()->routeIs('tables'),
                                    'admin-nav-inactive' => ! request()->routeIs('tables'),
                                ])
                                :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                                title="All tables"
                            >
                                <x-mary-icon name="o-table-cells" class="h-[18px] w-[18px] shrink-0" />
                                <span x-show="!sidebarCollapsed" x-transition.opacity class="min-w-0 whitespace-normal leading-5">All tables</span>
                            </a>
                        </div>

            <div class="my-4 border-t border-base-300"></div>

            <p x-show="!sidebarCollapsed" class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-base-content/60">System</p>
            <div class="space-y-1">
                @foreach ($utilityItems as $item)
                    @php $active = request()->routeIs($item['route']); @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        data-sidebar-link
                        data-active="admin-nav-active"
                        data-inactive="admin-nav-inactive"
                        @class([
                            'group flex min-h-10 items-center rounded-md py-2 text-sm font-semibold transition',
                            'admin-nav-active' => $active,
                            'admin-nav-inactive' => ! $active,
                        ])
                        :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                        @if($active) aria-current="page" @endif
                        title="{{ $item['label'] }}"
                    >
                        <x-mary-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0" />
                        <span x-show="!sidebarCollapsed" x-transition.opacity class="min-w-0 whitespace-normal leading-5">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>

            @if (count($adminItems) > 0)
                <div class="my-4 border-t border-base-300"></div>

                <p x-show="!sidebarCollapsed" class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-base-content/60">Administration</p>
                <div class="space-y-1">
                    @foreach ($adminItems as $item)
                        @php $active = request()->routeIs($item['route']); @endphp
                        <a
                            href="{{ route($item['route']) }}"
                            wire:navigate
                            data-sidebar-link
                            data-active="admin-nav-active"
                            data-inactive="admin-nav-inactive"
                            @class([
                                'group flex min-h-10 items-center rounded-md py-2 text-sm font-semibold transition',
                                'admin-nav-active' => $active,
                                'admin-nav-inactive' => ! $active,
                            ])
                            :class="sidebarCollapsed ? 'justify-center px-0' : 'gap-3 px-3'"
                            @if($active) aria-current="page" @endif
                            title="{{ $item['label'] }}"
                        >
                            <x-mary-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0" />
                            <span x-show="!sidebarCollapsed" x-transition.opacity class="min-w-0 whitespace-normal leading-5">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </nav>

        <footer class="flex min-h-16 shrink-0 items-center border-t border-base-300 px-3 py-3" :class="sidebarCollapsed ? 'flex justify-center' : ''">
            @php
                // Real import freshness, not a decorative status light — the
                // dashboards are only as current as the last completed import.
                $latestImport = \App\Models\ImportBatch::query()->latest('completed_at')->first();
                $importFailed = ($latestImport?->status) === 'failed';
            @endphp
            <div x-show="!sidebarCollapsed" x-transition.opacity class="flex w-full items-center justify-between gap-2 px-2">
                <span class="flex min-w-0 items-center gap-2 text-[11px] font-semibold {{ $importFailed ? 'text-error' : 'text-base-content/50' }}" title="{{ $latestImport ? 'Last import '.($latestImport->status).($latestImport->completed_at ? ' '.$latestImport->completed_at->diffForHumans() : '') : 'No imports yet' }}">
                    <span class="relative flex h-1.5 w-1.5 shrink-0">
                        <span class="absolute inline-flex h-full w-full rounded-full {{ $importFailed ? 'bg-error/60' : 'bg-success/60' }} opacity-75"></span>
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full {{ $importFailed ? 'bg-error' : 'bg-success' }}"></span>
                    </span>
                    <span class="truncate">{{ $latestImport?->completed_at?->diffForHumans() ?? 'No imports yet' }}</span>
                </span>
            </div>
            <span x-show="sidebarCollapsed" x-transition.opacity class="relative flex h-1.5 w-1.5" title="{{ $latestImport ? 'Last import '.($latestImport->status).($latestImport->completed_at ? ' '.$latestImport->completed_at->diffForHumans() : '') : 'No imports yet' }}">
                <span class="absolute inline-flex h-full w-full rounded-full {{ $importFailed ? 'bg-error/60' : 'bg-success/60' }} opacity-75"></span>
                <span class="relative inline-flex h-1.5 w-1.5 rounded-full {{ $importFailed ? 'bg-error' : 'bg-success' }}"></span>
            </span>
            <button type="button" x-on:click="sidebarCollapsed = !sidebarCollapsed" :aria-label="sidebarCollapsed ? 'Expand navigation' : 'Collapse navigation'" :aria-expanded="!sidebarCollapsed" aria-controls="admin-navigation" class="admin-icon-button hidden shrink-0 lg:inline-flex">
                <x-mary-icon name="o-chevron-double-left" class="h-4 w-4 transition-transform" ::class="sidebarCollapsed ? 'rotate-180' : ''" />
            </button>
        </footer>
    </aside>
</div>


