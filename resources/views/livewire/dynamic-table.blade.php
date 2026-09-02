<div class="space-y-5">
    {{-- monday.com live-sync panel for a user-created table. The pull toggle
         lives here (per-table) and only triggers when the global flag is on
         and a board is connected. --}}
    <section class="admin-surface p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h2 class="flex items-center gap-2 text-sm font-bold text-base-content">
                    <span class="flex h-7 w-7 items-center justify-center rounded-md bg-primary/12 text-primary">
                        <x-mary-icon name="o-at-symbol" class="h-4 w-4" />
                    </span>
                    monday.com live sync
                </h2>
                <p class="mt-1.5 text-xs leading-5 text-base-content/55">
                    When ON, newly created items on the connected monday.com board are pulled
                    into this table and mapped column-by-column. Backfill via the Import table
                    button is independent of this toggle.
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-base-200/70 px-3 py-1 font-semibold text-base-content/70">
                        Board:
                        <span class="font-bold text-base-content">
                            {{ $mondayBoardId ?: 'Not connected' }}
                        </span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 font-semibold {{ $mondayEnabled ? 'bg-success/15 text-success' : 'bg-base-200/70 text-base-content/55' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $mondayEnabled ? 'bg-success' : 'bg-base-content/30' }}"></span>
                        Pulling new items: {{ $mondayEnabled ? 'ON' : 'OFF' }}
                    </span>
                    @if (! $mondayGlobalEnabled)
                        <span class="rounded-full bg-error/10 px-3 py-1 font-semibold text-error">monday sync disabled globally (MONDAY_SYNC_ENABLED=false)</span>
                    @endif
                    @if ($mondayLastSyncedAt)
                        <span class="rounded-full bg-base-200/70 px-3 py-1 font-semibold text-base-content/60">
                            Last sync: {{ $mondayLastSyncedAt->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </div>

            @if ($editable)
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="openConnectBoard" class="admin-secondary-button">
                        <x-mary-icon name="o-link" class="h-4 w-4" />
                        {{ $mondayBoardId ? 'Change board' : 'Connect board' }}
                    </button>
                    <button
                        type="button"
                        wire:click="syncNow"
                        class="admin-secondary-button"
                        wire:loading.attr="disabled"
                        wire:target="syncNow"
                        @disabled(! $mondayBoardId || ! $mondayGlobalEnabled)
                    >
                        <span wire:loading.remove wire:target="syncNow">
                            <x-mary-icon name="o-arrow-path" class="h-4 w-4" />
                            Sync now
                        </span>
                        <span wire:loading wire:target="syncNow">Syncing…</span>
                    </button>
                    <button type="button" wire:click="toggleMondayPull" class="admin-primary-button" @disabled(! $mondayBoardId || ! $mondayGlobalEnabled)">
                        <x-mary-icon name="{{ $mondayEnabled ? 'o-no-symbol' : 'o-play' }}" class="h-4 w-4" />
                        {{ $mondayEnabled ? 'Turn off' : 'Turn on' }}
                    </button>
                </div>
            @endif
        </div>

        @if (session('mondayMessage'))
            <p class="mt-3 rounded-md border border-primary/20 bg-primary/5 px-3 py-2 text-xs font-medium text-primary">{{ session('mondayMessage') }}</p>
        @endif
        @if ($editable && ! $mondayBoardId)
            <p class="mt-3 text-xs text-base-content/50">Connect a monday.com board first, then turn on live pull. Column auto-mapping from the board's real columns arrives with the monday connect milestone.</p>
        @endif
    </section>

    @include('livewire.partials.managed-table-grid')

    <x-admin.modal name="connect-board" title="Connect monday.com board" description="Paste the monday.com board id (the numeric id from the board URL) that should feed new items into this table." size="md">
        <form wire:submit="connectBoard" class="space-y-4">
            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Board id</label>
                <input type="text" wire:model="connectBoardId" class="admin-control w-full" placeholder="e.g. 1771812698" x-bind:autofocus="true">
                <x-input-error :messages="$errors->get('connectBoardId')" class="mt-1.5" />
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-base-300 pt-4">
                <button type="button" x-on:click="$dispatch('close-modal', { name: 'connect-board' })" class="admin-secondary-button">Cancel</button>
                <button type="submit" class="admin-primary-button">Connect</button>
            </div>
        </form>
    </x-admin.modal>
</div>
