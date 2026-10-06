{{--
    Shared monday.com connect panel + board-picker modal — included above the
    grid by every table (the five core tables via managed-table.blade, dynamic
    tables via dynamic-table.blade). Reads the $monday array from the shared
    ConnectsMondayBoard::mondayViewData(); actions are gated on $canImport.

    Visibility contract:
      - core tables: shown only when the viewer may import, or a board is
        already connected (viewers of an unconnected table see no noise);
      - dynamic tables: always shown (existing behaviour).
--}}
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
                into this table and mapped column-by-column.
            </p>

            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-base-200/70 px-3 py-1 font-semibold text-base-content/70">
                    Board:
                    <span class="font-bold text-base-content">
                        {{ $monday['mondayBoardId'] ?: 'Not connected' }}
                    </span>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 font-semibold {{ $monday['mondayEnabled'] ? 'bg-success/15 text-success' : 'bg-base-200/70 text-base-content/55' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $monday['mondayEnabled'] ? 'bg-success' : 'bg-base-content/30' }}"></span>
                    Pulling new items: {{ $monday['mondayEnabled'] ? 'ON' : 'OFF' }}
                </span>
                @if (! $monday['mondayGlobalEnabled'])
                    <span class="rounded-full bg-error/10 px-3 py-1 font-semibold text-error">monday sync off globally (Settings → monday.com)</span>
                @endif
                @if ($monday['mondayLastSyncedAt'])
                    <span class="rounded-full bg-base-200/70 px-3 py-1 font-semibold text-base-content/60">
                        Last sync: {{ $monday['mondayLastSyncedAt']->diffForHumans() }}
                    </span>
                @endif
            </div>
        </div>

        @if ($canImport)
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="openConnectBoard" class="admin-secondary-button">
                    <x-mary-icon name="o-link" class="h-4 w-4" />
                    {{ $monday['mondayBoardId'] ? 'Change board' : 'Connect board' }}
                </button>
                <button
                    type="button"
                    wire:click="syncNow"
                    class="admin-secondary-button"
                    wire:loading.attr="disabled"
                    wire:target="syncNow"
                    @disabled(! $monday['mondayBoardId'] || ! $monday['mondayGlobalEnabled'])
                >
                    <span wire:loading.remove wire:target="syncNow">
                        <x-mary-icon name="o-arrow-path" class="h-4 w-4" />
                        Sync now
                    </span>
                    <span wire:loading wire:target="syncNow">Syncing…</span>
                </button>
                <button type="button" wire:click="toggleMondayPull" class="admin-primary-button" @disabled(! $monday['mondayBoardId'] || ! $monday['mondayGlobalEnabled'])>
                    <x-mary-icon name="{{ $monday['mondayEnabled'] ? 'o-no-symbol' : 'o-play' }}" class="h-4 w-4" />
                    {{ $monday['mondayEnabled'] ? 'Turn off' : 'Turn on' }}
                </button>
                @if ($monday['mondayBoardId'])
                    <button type="button" wire:click="disconnectBoard" class="admin-secondary-button" wire:loading.attr="disabled" wire:target="disconnectBoard">
                        Disconnect
                    </button>
                @endif
            </div>
        @endif
    </div>

    @if ($monday['mondayMessage'] !== '')
        <p class="mt-3 rounded-md border px-3 py-2 text-xs font-medium {{ $monday['mondayMessageTone'] === 'success' ? 'border-success/20 bg-success/5 text-success' : ($monday['mondayMessageTone'] === 'error' ? 'border-error/20 bg-error/5 text-error' : 'border-primary/20 bg-primary/5 text-primary') }}">
            {{ $monday['mondayMessage'] }}
        </p>
    @endif
</section>

<x-admin.modal
    name="connect-board"
    title="Connect monday.com board"
    description="Choose a board from your monday.com account (loaded with your token), or paste its numeric id from the board URL. The board's columns become this table's columns automatically."
    size="md"
>
    <form wire:submit="connectBoard" class="space-y-4">
        @if ($monday['mondayBoards'] !== [])
            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Your boards</label>
                <select wire:model.live="connectBoardId" class="admin-control w-full">
                    <option value="">— choose a board —</option>
                    @foreach ($monday['mondayBoards'] as $board)
                        <option value="{{ $board['id'] }}">{{ $board['name'] }} ({{ $board['id'] }})</option>
                    @endforeach
                </select>
            </div>
        @else
            <p class="rounded-md border border-base-300 bg-base-200/50 px-3 py-2 text-xs text-base-content/60">
                No boards loaded — enter the board id manually below. (Save a token in Settings → monday.com so boards can be listed.)
            </p>
        @endif

        <div>
            <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Board id (manual entry)</label>
            <input type="text" wire:model="connectBoardId" class="admin-control w-full" placeholder="e.g. 1771812698" x-bind:autofocus="true">
            <x-input-error :messages="$errors->get('connectBoardId')" class="mt-1.5" />
        </div>

        <label class="flex items-start gap-3 rounded-md border border-base-300 p-3">
            <input type="checkbox" wire:model="mondayBackfill" class="checkbox checkbox-primary mt-0.5">
            <span class="text-sm text-base-content">
                <span class="font-semibold">Import items already on the board now</span>
                <span class="block text-xs leading-5 text-base-content/55">
                    Recommended — existing items are pulled in right away. New items keep arriving automatically afterwards.
                </span>
            </span>
        </label>

        @if ($monday['mondayTitleFieldOptions'] !== [])
            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Item name → column</label>
                <select wire:model="mondayTitleField" class="admin-control w-full">
                    @foreach ($monday['mondayTitleFieldOptions'] as $field => $label)
                        <option value="{{ $field }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-base-content/50">Where the board item's name lands in this table. You can remap it later by reconnecting.</p>
            </div>
        @endif

        <div class="flex items-center justify-end gap-2 border-t border-base-300 pt-4">
            <button type="button" x-on:click="$dispatch('close-modal', { name: 'connect-board' })" class="admin-secondary-button">Cancel</button>
            <button type="submit" class="admin-primary-button" wire:loading.attr="disabled" wire:target="connectBoard">
                <span wire:loading.remove wire:target="connectBoard">Connect</span>
                <span wire:loading wire:target="connectBoard">Connecting…</span>
            </button>
        </div>
    </form>
</x-admin.modal>
