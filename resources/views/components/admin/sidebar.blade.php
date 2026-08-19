@php
    $primaryItems = [
        ['route' => 'dashboard', 'label' => 'Home', 'description' => 'Overview', 'icon' => 'o-home'],
        ['route' => 'tsp-analytics', 'label' => 'TSP Analytics', 'description' => 'Personnel performance', 'icon' => 'o-chart-bar-square'],
        ['route' => 'records', 'label' => 'Records table', 'description' => 'Imported records', 'icon' => 'o-table-cells'],
    ];

    $utilityItems = [
        ['route' => 'settings', 'label' => 'Settings', 'icon' => 'o-cog-6-tooth'],
        ['route' => 'help-center', 'label' => 'Help center', 'icon' => 'o-question-mark-circle'],
    ];
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
            <p x-show="!sidebarCollapsed" class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.16em] text-base-content/40">Workspace</p>

            <div class="space-y-1">
                @foreach ($primaryItems as $item)
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
        </nav>

    </aside>
</div>
