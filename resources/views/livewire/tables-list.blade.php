<div class="space-y-5">
    <x-admin.page-header eyebrow="Workspace" title="Tables" description="The managed data tables that power dashboards and reporting. Import Excel or CSV files to refresh them.">
        <x-slot name="actions">
            <a href="{{ route('dashboard') }}" wire:navigate class="admin-secondary-button">View dashboard</a>
            @can('import', App\Models\Installation::class)
                <button type="button" x-on:click="$dispatch('open-modal', { name: 'create-table' })" class="admin-secondary-button">
                    <x-mary-icon name="o-plus" class="h-4 w-4" />
                    New table
                </button>
                <button type="button" x-on:click="$dispatch('open-modal', { name: 'import-table' })" class="admin-primary-button">
                    <x-mary-icon name="o-arrow-up-tray" class="h-4 w-4" />
                    Import table
                </button>
            @endcan
        </x-slot>
    </x-admin.page-header>

    <div class="flex items-center justify-between gap-2">
        <p class="text-xs text-base-content/50">
            All tables live here — pin the ones you use often and they appear in the side navigation.
            @if ($pinnedCount > 0)
                <span class="font-semibold text-primary">{{ $pinnedCount }} pinned</span>
            @endif
        </p>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($tables as $table)
            <article class="admin-surface flex min-h-[200px] flex-col p-5 transition hover:-translate-y-0.5 hover:border-primary/40">
                <div class="flex items-start justify-between gap-4">
                    <a href="{{ $table['url'] }}" wire:navigate class="min-w-0">
                        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/12 text-primary">
                            <x-mary-icon name="{{ $table['icon'] }}" class="h-5 w-5" />
                        </span>
                        <h2 class="mt-4 truncate text-lg font-bold text-base-content">{{ $table['label'] }}</h2>
                        <p class="mt-1 line-clamp-2 text-sm leading-5 text-base-content/55">{{ $table['description'] }}</p>
                    </a>
                    <button
                        type="button"
                        wire:click="togglePin('{{ $table['key'] }}')"
                        class="admin-icon-button shrink-0"
                        :class="$table['pinned'] ? 'text-primary' : 'text-base-content/30'"
                        title="{{ $table['pinned'] ? 'Unpin from sidebar' : 'Pin to sidebar' }}"
                        aria-label="{{ $table['pinned'] ? 'Unpin' : 'Pin' }}"
                        aria-pressed="{{ $table['pinned'] ? 'true' : 'false' }}"
                    >
                        <x-mary-icon name="{{ $table['pinned'] ? 's-bookmark' : 'o-bookmark' }}" class="h-4 w-4" />
                    </button>
                </div>
                <div class="mt-auto grid grid-cols-2 gap-2 border-t border-base-300 pt-4 text-xs">
                    <div>
                        <p class="font-bold tabular-nums text-base-content">{{ number_format($table['count']) }}</p>
                        <p class="mt-1 text-base-content/45">Records</p>
                    </div>
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-base-content/70" title="{{ $table['source'] }}">{{ $table['source'] }}</p>
                        <p class="mt-1 text-base-content/45">Source</p>
                    </div>
                </div>
                <a href="{{ $table['url'] }}" wire:navigate class="admin-secondary-button mt-4 w-full justify-center">Open table</a>
            </article>
        @endforeach
    </div>

    <section class="admin-surface p-5 sm:p-6">
        <h2 class="text-base font-bold text-base-content">Recent imports</h2>
        <p class="mt-1 text-xs text-base-content/55">Every Excel/CSV import is tracked with a batch ID and row counts.</p>
        <div class="mt-4 divide-y divide-base-300">
            @forelse ($imports as $import)
                <div class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-base-content">{{ $import->source_name }}</p>
                        <p class="text-xs text-base-content/50">{{ $import->source_sheet }} · batch #{{ $import->id }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-semibold text-base-content">{{ number_format($import->processed_rows) }} rows</p>
                        <p class="text-xs text-base-content/50">{{ $import->status }} · {{ optional($import->completed_at)->diffForHumans() ?? 'pending' }}</p>
                    </div>
                </div>
            @empty
                <x-admin.empty-state
                    icon="o-arrow-up-tray"
                    title="No imports yet"
                    description="Import an Excel/CSV source into a managed table and it will be tracked here with a batch ID and row counts."
                />
            @endforelse
        </div>
    </section>

    <section class="admin-surface p-5 sm:p-6">
        <h2 class="text-base font-bold text-base-content">Recent manual edits</h2>
        <p class="mt-1 text-xs text-base-content/55">Who changed which record by hand — import-driven updates are tracked under Recent imports.</p>
        <div class="mt-4 divide-y divide-base-300">
            @forelse ($recentEdits as $edit)
                <div class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-base-content">
                            {{ ucfirst($edit->action) }}{{ $edit->field ? ' · '.$edit->field : '' }}
                            <span class="font-normal text-base-content/50">on {{ $edit->table_key }} #{{ $edit->row_id }}</span>
                        </p>
                        <p class="text-xs text-base-content/50">{{ $edit->user?->name ?? 'System' }} · {{ $edit->created_at->diffForHumans() }}</p>
                    </div>
                    <div class="hidden max-w-[28ch] truncate text-right text-xs text-base-content/45 sm:block" title="{{ $edit->old_value ?? '' }} → {{ $edit->new_value ?? '' }}">
                        {{ Str::limit(($edit->old_value ?? '∅').' → '.($edit->new_value ?? '∅'), 40) }}
                    </div>
                </div>
            @empty
                <p class="py-3 text-sm text-base-content/45">No manual edits yet — only Superadmins can edit records.</p>
            @endforelse
        </div>
    </section>

    <x-admin.modal name="create-table" title="New table" description="Name the table and define its columns while building it — just like laying out a spreadsheet to mirror a monday.com board." size="lg">
        <form wire:submit="createTable" class="space-y-5">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Table name</label>
                    <input type="text" wire:model="newTableName" class="admin-control w-full" placeholder="e.g. Equipment Requests">
                    <x-input-error :messages="$errors->get('newTableName')" class="mt-1.5" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Description</label>
                    <input type="text" wire:model="newTableDescription" class="admin-control w-full" placeholder="Optional">
                </div>
            </div>

            <div>
                <p class="mb-2 text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Columns</p>
                <div class="space-y-2">
                    @foreach ($draftColumns as $index => $draftColumn)
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                wire:model="draftColumns.{{ $index }}.name"
                                class="admin-control flex-1"
                                placeholder="Column name (e.g. Status, Brand, Due date)">
                            <select wire:model="draftColumns.{{ $index }}.type" class="admin-control w-44">
                                @foreach ($columnTypeOptions as $key => $type)
                                    <option value="{{ $key }}">{{ \Illuminate\Support\Str::headline($key) }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="removeDraftColumn({{ $index }})" class="admin-icon-button" aria-label="Remove column">
                                <x-mary-icon name="o-x-mark" class="h-4 w-4" />
                            </button>
                        </div>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('draftColumns')" class="mt-1.5" />
                <button type="button" wire:click="addDraftColumn" class="admin-secondary-button mt-2">
                    <x-mary-icon name="o-plus" class="h-4 w-4" />
                    Add column
                </button>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-base-300 pt-4">
                <button type="button" x-on:click="$dispatch('close-modal', { name: 'create-table' })" class="admin-secondary-button">Cancel</button>
                <button type="submit" class="admin-primary-button">
                    <x-mary-icon name="o-check" class="h-4 w-4" />
                    Create table
                </button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal name="import-table" title="Import table" description="Choose a managed table and upload its Excel/CSV source.">
        <p class="text-sm text-base-content/60">Open the target table first, then use its Import table button to run a re-import.</p>
    </x-admin.modal>
</div>
