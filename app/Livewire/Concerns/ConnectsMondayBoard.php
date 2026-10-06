<?php

namespace App\Livewire\Concerns;

use App\Models\MondaySyncSetting;
use App\Services\MondayApiClient;
use App\Services\MondayItemMapper;
use App\Services\MondaySyncService;
use App\Support\MondayCoreTargets;
use App\Support\MondaySettings;
use Throwable;

/**
 * The monday.com connect menu shared by every table — the five core tables
 * (used via ManagedTable) and user-created dynamic tables.
 *
 * Provides the panel state + actions: board picker (openConnectBoard loads
 * the token's boards so the user chooses instead of typing ids), connect with
 * auto-created columns and an optional backfill (default ON), live-pull
 * toggle, manual sync, and disconnect. All writes are gated on canImport —
 * connecting a board feeds the importer, so it sits at the same bar as
 * running an import (dynamic tables additionally require table-level edit
 * via their canImport() override).
 *
 * Domain resolution goes through mondayDomainKey() (tableKey() by default);
 * persistMondayBoard() writes the canonical monday_sync_settings row and is
 * overridden by DynamicTable to mirror the id onto its registry column.
 */
trait ConnectsMondayBoard
{
    public ?string $mondayBoardId = null;

    public bool $mondayEnabled = false;

    public bool $showConnectBoardModal = false;

    public string $connectBoardId = '';

    /** @var array<int, array{id: string, name: string}> boards the token can see */
    public array $mondayBoards = [];

    /** Backfill checkbox at connect time — default ON (import what's already there). */
    public bool $mondayBackfill = true;

    /** Core tables: which domain field receives the board item's name. */
    public string $mondayTitleField = '';

    public string $mondayMessage = '';

    public string $mondayMessageTone = 'info';

    protected function mondayDomainKey(): string
    {
        return $this->tableKey();
    }

    protected function mondaySetting(): MondaySyncSetting
    {
        return MondaySyncSetting::forDomain($this->mondayDomainKey());
    }

    /** Load this table's connection state into the component props. */
    protected function hydrateMondayState(): void
    {
        $setting = $this->mondaySetting();

        $this->mondayBoardId = $setting->board_id;
        $this->mondayEnabled = (bool) $setting->enabled;
        $this->mondayTitleField = (string) ($setting->title_field ?? '');

        $target = MondayCoreTargets::resolve($this->mondayDomainKey());
        if ($target !== null && $this->mondayTitleField === '') {
            $this->mondayTitleField = $target['title'];
        }
    }

    /** Canonical connection write: monday_sync_settings.board_id. */
    protected function persistMondayBoard(?string $boardId): void
    {
        $this->mondaySetting()->update(['board_id' => $boardId]);
    }

    /**
     * Open the connect modal with the board picker prefilled from the token's
     * monday.com boards. Falls back to manual id entry when the boards cannot
     * be listed (token not configured yet, API hiccup).
     */
    public function openConnectBoard(): void
    {
        abort_unless($this->canImport(), 403);

        $this->hydrateMondayState();
        $this->connectBoardId = (string) ($this->mondayBoardId ?? '');
        $this->mondayBackfill = $this->mondayBoardId === null;
        $this->mondayMessage = '';
        $this->mondayMessageTone = 'info';
        $this->mondayBoards = [];

        if (app(MondayApiClient::class)->configured()) {
            try {
                $this->mondayBoards = collect(app(MondayApiClient::class)->boards())
                    ->map(fn (array $board): array => [
                        'id' => (string) ($board['id'] ?? ''),
                        'name' => (string) ($board['name'] ?? ''),
                    ])
                    ->filter(fn (array $board): bool => $board['id'] !== '')
                    ->values()
                    ->all();
            } catch (Throwable $exception) {
                $this->mondayMessage = 'Could not load your monday.com boards: '.$exception->getMessage().' — you can still enter a board id manually.';
                $this->mondayMessageTone = 'error';
            }
        }

        $this->showConnectBoardModal = true;
        $this->dispatch('open-modal', name: 'connect-board');
    }

    /**
     * Connect the chosen board: persist the id, auto-create the board's real
     * columns (fast auto-map), optionally remap the title field (core
     * tables), then — when the backfill checkbox is on — enable live pull and
     * import the items already on the board.
     */
    public function connectBoard(): void
    {
        abort_unless($this->canImport(), 403);

        $boardId = trim($this->connectBoardId);

        if ($boardId === '') {
            $this->addError('connectBoardId', 'Choose a board (or enter its id).');

            return;
        }

        // 1. Persist the connection (setting row + any registry mirror).
        $this->persistMondayBoard($boardId);
        $this->mondayBoardId = $boardId;

        // 2. Optional title-field remap (core tables only — dynamic tables'
        //    name column is fixed).
        $titleOptions = MondayCoreTargets::titleFieldOptions($this->mondayDomainKey());
        if ($titleOptions !== [] && array_key_exists($this->mondayTitleField, $titleOptions)) {
            $this->mondaySetting()->update(['title_field' => $this->mondayTitleField]);
        }

        // 3. Auto-map the board's real columns into table columns.
        $created = 0;

        try {
            $boardColumns = app(MondayApiClient::class)->boardColumns((int) $boardId);
            $created = app(MondayItemMapper::class)->autoCreateColumns(
                $this->mondayDomainKey(),
                $boardColumns,
                auth()->user()?->id,
            );
        } catch (Throwable $exception) {
            $this->closeConnectBoardModal();
            $this->mondayMessage = 'Board '.$boardId.' saved, but fetching its columns failed: '.$exception->getMessage();
            $this->mondayMessageTone = 'error';

            return;
        }

        $message = 'Board '.$boardId.' connected — '.$created.' column(s) auto-created to match the board.';
        $tone = 'success';

        // 4. Backfill (default ON): turn the pull on and import what is
        //    already on the board right now.
        if ($this->mondayBackfill) {
            $this->mondaySetting()->update(['enabled' => true]);
            $this->mondayEnabled = true;

            if (! MondaySettings::enabled()) {
                $message .= ' Live pull is waiting: the global monday.com switch is off — turn it on in Settings → monday.com.';
                $tone = 'error';
            } else {
                try {
                    $result = app(MondaySyncService::class)->syncDomain($this->mondayDomainKey());

                    if (($result['status'] ?? '') === 'ok') {
                        $message .= sprintf(
                            ' Imported %d new item(s), %d failed.',
                            (int) ($result['imported'] ?? 0),
                            (int) ($result['failed'] ?? 0),
                        );
                    } else {
                        $message .= ' Backfill skipped: '.($result['message'] ?? 'sync not ready.');
                        $tone = 'error';
                    }
                } catch (Throwable $exception) {
                    $message .= ' Backfill failed: '.$exception->getMessage();
                    $tone = 'error';
                }
            }
        }

        $this->closeConnectBoardModal();
        $this->mondayMessage = $message;
        $this->mondayMessageTone = $tone;
    }

    protected function closeConnectBoardModal(): void
    {
        $this->showConnectBoardModal = false;
        $this->dispatch('close-modal', name: 'connect-board');
    }

    /** Per-table live-pull switch (the every-minute poll honors it). */
    public function toggleMondayPull(): void
    {
        abort_unless($this->canImport(), 403);

        $setting = $this->mondaySetting();
        $setting->update(['enabled' => ! $setting->enabled]);
        $this->mondayEnabled = $setting->enabled;

        $this->mondayMessage = $this->mondayEnabled
            ? 'Live pull for new monday.com items is now ON (next sync picks up new items).'
            : 'Live pull for new monday.com items is now OFF.';
        $this->mondayMessageTone = 'info';
    }

    /**
     * Manually run the new-item pull for this table now (Superadmin escape
     * hatch when the scheduler isn't running or an operator wants an immediate
     * sync). Validates the global flag + token, then reports what happened.
     */
    public function syncNow(): void
    {
        abort_unless($this->canImport(), 403);

        if (! MondaySettings::enabled()) {
            $this->mondayMessage = 'monday sync is disabled globally. Enable it in Settings → monday.com.';
            $this->mondayMessageTone = 'error';

            return;
        }

        try {
            $result = app(MondaySyncService::class)->syncDomain($this->mondayDomainKey());
        } catch (Throwable $exception) {
            $this->mondayMessage = 'Sync failed: '.$exception->getMessage();
            $this->mondayMessageTone = 'error';

            return;
        }

        $this->mondayMessage = match ($result['status']) {
            'disabled' => $result['message'] ?? 'Sync is not ready.',
            'error' => $result['message'] ?? 'Sync errored.',
            default => sprintf(
                'Sync done — %d new item(s), %d imported, %d failed.',
                (int) ($result['new'] ?? 0),
                (int) ($result['imported'] ?? 0),
                (int) ($result['failed'] ?? 0),
            ),
        };
        $this->mondayMessageTone = ($result['status'] ?? '') === 'ok' ? 'success' : 'error';
    }

    /**
     * Unlink the board. Columns and already-imported rows are kept (they are
     * real table data now); only the connection + pull toggle are cleared.
     */
    public function disconnectBoard(): void
    {
        abort_unless($this->canImport(), 403);

        $this->persistMondayBoard(null);

        $setting = $this->mondaySetting();
        $setting->update(['enabled' => false]);

        $this->mondayBoardId = null;
        $this->mondayEnabled = false;
        $this->mondayMessage = 'Board disconnected — your columns and imported rows were kept.';
        $this->mondayMessageTone = 'info';
    }

    /**
     * Everything the shared monday-sync partial needs, read fresh from the DB
     * so every render shows the truth (props only change through actions).
     * Uses a non-creating read — viewing a table never spawns setting rows.
     *
     * @return array<string, mixed>
     */
    public function mondayViewData(): array
    {
        $setting = MondaySyncSetting::query()
            ->where('domain', $this->mondayDomainKey())
            ->first();

        return [
            'mondayBoardId' => $setting?->board_id,
            'mondayEnabled' => (bool) ($setting?->enabled ?? false),
            'mondayGlobalEnabled' => MondaySettings::enabled(),
            'mondayLastSyncedAt' => $setting?->last_synced_at,
            'mondayLastItemId' => $setting?->last_item_id_seen,
            'mondayBoards' => $this->mondayBoards,
            'mondayBackfill' => $this->mondayBackfill,
            'mondayTitleField' => $this->mondayTitleField,
            'mondayTitleFieldOptions' => MondayCoreTargets::titleFieldOptions($this->mondayDomainKey()),
            'mondayMessage' => $this->mondayMessage,
            'mondayMessageTone' => $this->mondayMessageTone,
        ];
    }
}
