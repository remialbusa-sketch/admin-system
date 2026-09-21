<div class="space-y-6">
    <x-admin.page-header eyebrow="Workspace" title="Dashboards" description="Create dashboards, connect the tables they read from, and share them with people.">
        <x-slot name="actions">
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
                                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.08em] text-primary" title="Shared template — opening it gives you an editable copy">Template</span>
                                            @endif
                                        </h3>
                                        <p class="mt-1 text-xs text-base-content/50">
                                            {{ $dashboard->sources->count() }} source{{ $dashboard->sources->count() === 1 ? '' : 's' }}
                                            &middot; {{ count($dashboard->layout['widgets'] ?? []) }} widget{{ count($dashboard->layout['widgets'] ?? []) === 1 ? '' : 's' }}
                                        </p>
                                    </div>
                                    @if ($dashboard->owner_id === auth()->id() && ! $dashboard->is_system)
                                        <div class="flex shrink-0 items-center gap-1">
                                            <button type="button" wire:click="startRename({{ $dashboard->id }}, @js($dashboard->name))" class="admin-icon-button" aria-label="Rename">
                                                <x-mary-icon name="o-pencil" class="h-4 w-4" />
                                            </button>
                                            <button type="button" wire:click="deleteDashboard({{ $dashboard->id }})" wire:confirm="Delete this dashboard? Widgets and shares are removed; the tables themselves are untouched." class="admin-icon-button" aria-label="Delete">
                                                <x-mary-icon name="o-trash" class="h-4 w-4" />
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

    <x-admin.modal name="create-dashboard" title="New dashboard" description="Name it, then connect the tables it should read from on the dashboard page." size="md">
        <form wire:submit="createDashboard" class="space-y-4">
            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Name</label>
                <input type="text" wire:model="newName" class="admin-control w-full" placeholder="e.g. Visayas operations">
                <x-input-error :messages="$errors->get('newName')" class="mt-1.5" />
            </div>
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
