<div class="space-y-6">
    <x-admin.page-header eyebrow="Workspace" title="Dashboards" description="Create dashboards, connect the tables they read from, and share them with people.">
        <x-slot name="actions">
            <a href="{{ route('visualize') }}" class="admin-secondary-button" title="Create one chart or number widget and add it to a dashboard">
                <x-mary-icon name="o-chart-bar" class="h-4 w-4" />
                New visualization
            </a>
            <button type="button" x-on:click="$dispatch('open-modal', { name: 'create-dashboard' })" class="admin-primary-button">
                <x-mary-icon name="o-plus" class="h-4 w-4" />
                New dashboard
            </button>
        </x-slot>
    </x-admin.page-header>

    @foreach ([
        ['title' => 'My dashboards', 'items' => $owned, 'empty' => 'You have not created a dashboard yet.'],
        ['title' => 'Shared with me', 'items' => $shared, 'empty' => 'No dashboards have been shared with you yet.'],
        ['title' => 'System dashboards', 'items' => $system, 'empty' => 'No system dashboards.'],
    ] as $section)
        <section class="space-y-3">
            <h2 class="text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">{{ $section['title'] }}</h2>

            @if ($section['items']->isEmpty())
                <p class="rounded-md border border-dashed border-base-300 px-4 py-6 text-center text-sm text-base-content/50">{{ $section['empty'] }}</p>
            @else
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($section['items'] as $dashboard)
                        <article class="admin-surface flex min-h-[150px] flex-col p-5 transition hover:-translate-y-0.5 hover:border-primary/40">
                            @if ($renamingId === $dashboard->id)
                                <form wire:submit="rename" class="flex items-center gap-2">
                                    <input type="text" wire:model="renamingName" class="admin-control flex-1" autofocus>
                                    <button type="submit" class="admin-primary-button">Save</button>
                                    <button type="button" wire:click="$set('renamingId', null)" class="admin-secondary-button">Cancel</button>
                                </form>
                            @else
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="flex items-center gap-2 truncate text-base font-bold text-base-content">
                                            {{ $dashboard->name }}
                                            @if ($dashboard->is_system)
                                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.08em] text-primary" title="Shared template — superadmins curate it; opening it gives everyone else an editable copy">Template</span>
                                            @endif
                                        </h3>
                                        <p class="mt-1 text-xs text-base-content/50">
                                            {{ $dashboard->sources->count() }} source{{ $dashboard->sources->count() === 1 ? '' : 's' }}
                                            &middot; {{ count($dashboard->layout['widgets'] ?? []) }} widget{{ count($dashboard->layout['widgets'] ?? []) === 1 ? '' : 's' }}
                                        </p>
                                    </div>
                                    @if (($dashboard->owner_id === auth()->id() || $isSuperadmin) && (! $dashboard->is_system || $isSuperadmin))
                                        <div class="flex shrink-0 items-center gap-1">
                                            <button type="button" wire:click="startRename({{ $dashboard->id }}, @js($dashboard->name))" class="admin-icon-button" aria-label="Rename">
                                                <x-mary-icon name="o-pencil" class="h-4 w-4" />
                                            </button>
                                            <button type="button" wire:click="deleteDashboard({{ $dashboard->id }})" wire:confirm="Archive this dashboard? You can restore it from the Archived section." class="admin-icon-button" aria-label="Archive">
                                                <x-mary-icon name="o-archive-box" class="h-4 w-4" />
                                            </button>
                                        </div>
                                    @endif
                                </div>

                                <div class="mt-auto pt-4">
                                    <a href="{{ route('dashboards.show', $dashboard) }}" wire:navigate class="admin-secondary-button">
                                        <x-mary-icon name="o-arrow-right" class="h-4 w-4" />
                                        Open
                                    </a>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach

    @if ($isSuperadmin)
        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">All dashboards (superadmin)</h2>
                <input type="search" wire:model.live.debounce.300ms="allSearch" placeholder="Search every dashboard..." class="admin-control h-8 w-64 py-1 text-xs" aria-label="Search all dashboards">
            </div>

            @if ($all->isEmpty())
                <p class="rounded-md border border-dashed border-base-300 px-4 py-6 text-center text-sm text-base-content/50">No dashboards match.</p>
            @else
                <div class="divide-y divide-base-300 rounded-md border border-base-300">
                    @foreach ($all as $dashboard)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 truncate text-sm font-semibold text-base-content">
                                    {{ $dashboard->name }}
                                    @if ($dashboard->is_system)
                                        <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.08em] text-primary">Template</span>
                                    @endif
                                </p>
                                <p class="truncate text-xs text-base-content/50">
                                    Owner: {{ $dashboard->owner?->name ?? 'System' }}
                                    &middot; {{ $dashboard->sources->count() }} source{{ $dashboard->sources->count() === 1 ? '' : 's' }}
                                    &middot; {{ count($dashboard->layout['widgets'] ?? []) }} widget{{ count($dashboard->layout['widgets'] ?? []) === 1 ? '' : 's' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button type="button" wire:click="startRename({{ $dashboard->id }}, @js($dashboard->name))" class="admin-secondary-button">Rename</button>
                                <button type="button" wire:click="deleteDashboard({{ $dashboard->id }})" wire:confirm="Archive this dashboard? You can restore it from the Archived section." class="admin-secondary-button">Archive</button>
                                <a href="{{ route('dashboards.show', $dashboard) }}" wire:navigate class="admin-primary-button">Open</a>
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-base-content/45">Showing up to 100 dashboards — use search to narrow. Every edit is recorded in the dashboard audit trail.</p>
            @endif
        </section>
    @endif

    @if ($archived->isNotEmpty())
        <section class="space-y-3">
            <h2 class="text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">Archived</h2>
            <div class="divide-y divide-base-300 rounded-md border border-base-300">
                @foreach ($archived as $dashboard)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-base-content/70">{{ $dashboard->name }}</p>
                            <p class="truncate text-xs text-base-content/45">
                                Archived {{ $dashboard->deleted_at?->diffForHumans() }}
                                &middot; {{ $dashboard->sources->count() }} source{{ $dashboard->sources->count() === 1 ? '' : 's' }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button type="button" wire:click="restoreDashboard({{ $dashboard->id }})" class="admin-secondary-button">
                                <x-mary-icon name="o-arrow-uturn-left" class="h-4 w-4" />
                                Restore
                            </button>
                            @if ($isSuperadmin)
                                <button type="button" wire:click="forceDeleteDashboard({{ $dashboard->id }})" wire:confirm="Permanently delete this dashboard? Sources and shares are removed. This cannot be undone." class="admin-icon-button" aria-label="Delete permanently">
                                    <x-mary-icon name="o-trash" class="h-4 w-4" />
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <x-admin.modal name="create-dashboard" title="New dashboard" description="Pick the table this dashboard will read from — it will start empty so you can add widgets for that table." size="md">
        <form wire:submit="createDashboard" class="space-y-4">
            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Name</label>
                <input type="text" wire:model="newName" class="admin-control w-full" placeholder="e.g. Visayas operations">
                <x-input-error :messages="$errors->get('newName')" class="mt-1.5" />
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Data source (table) <span class="text-error">*</span></label>
                <select wire:model="newDashboardTableKey" class="admin-control w-full">
                    <option value="">-- choose a table --</option>
                    @foreach ($tableOptions as $option)
                        <option value="{{ $option['key'] }}">{{ $option['label'] }} ({{ $option['key'] }})</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('newDashboardTableKey')" class="mt-1.5" />
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Alias (optional)</label>
                <input type="text" wire:model="newDashboardTableAlias" class="admin-control w-full font-mono text-xs" placeholder="auto: {{ Str::slug('your-table', '_') }}">
                <x-input-error :messages="$errors->get('newDashboardTableAlias')" class="mt-1.5" />
                <p class="mt-1 text-[11px] text-base-content/40">Lowercase letters, numbers and underscores. Leave blank to auto-generate from the table key.</p>
            </div>
            @if ($userOptions->isNotEmpty())
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Share with (optional) — toggle before creating</label>
                    <div class="flex gap-2">
                        <select wire:model="newDashboardShareUserIds" multiple class="admin-control flex-1 h-24 text-xs">
                            @foreach ($userOptions as $u)
                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                            @endforeach
                        </select>
                        <select wire:model="newDashboardSharePermission" class="admin-control w-28 text-xs">
                            <option value="view">View</option>
                            <option value="edit">Edit</option>
                        </select>
                    </div>
                    <p class="mt-1 text-[11px] text-base-content/40">Hold Ctrl/Cmd to select multiple people. The owner can share later from the dashboard page.</p>
                    <x-input-error :messages="$errors->get('newDashboardShareUserIds')" class="mt-1.5" />
                </div>
            @endif
            <div class="flex items-center justify-end gap-2 border-t border-base-300 pt-4">
                <button type="button" x-on:click="$dispatch('close-modal', { name: 'create-dashboard' })" class="admin-secondary-button">Cancel</button>
                <button type="submit" class="admin-primary-button">
                    <x-mary-icon name="o-check" class="h-4 w-4" />
                    Create dashboard
                </button>
            </div>
        </form>
    </x-admin.modal>
</div>