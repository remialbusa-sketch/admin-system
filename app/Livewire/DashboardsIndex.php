<?php

namespace App\Livewire;

use App\Models\Dashboard;
use App\Models\DashboardShare;
use App\Models\User;
use App\Support\DashboardAudit;
use App\Support\TableCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Dashboards index: the user's own dashboards, dashboards shared with them,
 * and system dashboards. Owners — and superadmins on any dashboard — can
 * rename, archive (soft delete) and restore; superadmins additionally curate
 * the system templates and can permanently delete.
 */
class DashboardsIndex extends Component
{
    /** Case-insensitive filter for the superadmin "All dashboards" list. */
    public string $allSearch = '';

    public string $newName = '';

    public string $newDashboardTableKey = '';

    public string $newDashboardTableAlias = '';

    /** @var array<int, int> */
    public array $newDashboardShareUserIds = [];

    public string $newDashboardSharePermission = 'view';

    public ?int $renamingId = null;

    public string $renamingName = '';

    public function createDashboard(): void
    {
        $user = auth()->user();

        abort_unless($user !== null, 403);

        $this->validate([
            'newName' => ['required', 'string', 'max:100'],
            'newDashboardTableKey' => ['required', 'string', 'max:64'],
            'newDashboardTableAlias' => ['nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'],
            'newDashboardShareUserIds' => ['array'],
            'newDashboardShareUserIds.*' => ['integer', 'exists:users,id'],
            'newDashboardSharePermission' => ['required', 'in:view,edit'],
        ]);

        if (! app(TableCatalog::class)->exists($this->newDashboardTableKey)) {
            $this->addError('newDashboardTableKey', 'That table does not exist.');

            return;
        }

        $alias = trim($this->newDashboardTableAlias) !== ''
            ? Str::lower(trim($this->newDashboardTableAlias))
            : Str::slug($this->newDashboardTableKey, '_');
        $alias = $alias !== '' ? $alias : 'source';

        $dashboard = Dashboard::create([
            'owner_id' => $user->id,
            'name' => trim($this->newName),
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        $dashboard->sources()->create([
            'table_key' => $this->newDashboardTableKey,
            'alias' => \App\Models\DashboardSource::uniqueAliasFor($dashboard, $alias),
            'position' => 0,
        ]);

        DashboardAudit::log($dashboard, 'created');
        DashboardAudit::log($dashboard, 'source_connected', [
            'table_key' => $this->newDashboardTableKey,
            'alias' => $alias,
        ]);

        foreach (array_unique(array_map('intval', $this->newDashboardShareUserIds)) as $shareUserId) {
            if ($shareUserId === $user->id) {
                continue;
            }

            DashboardShare::updateOrCreate(
                ['dashboard_id' => $dashboard->id, 'user_id' => $shareUserId],
                ['permission' => $this->newDashboardSharePermission, 'shared_by' => $user->id],
            );

            DashboardAudit::log($dashboard, 'shared', [
                'user_id' => $shareUserId,
                'permission' => $this->newDashboardSharePermission,
            ]);
        }

        $this->reset(['newName', 'newDashboardTableKey', 'newDashboardTableAlias', 'newDashboardShareUserIds']);
        $this->newDashboardSharePermission = 'view';

        $this->dispatch('dashboard-list-updated');

        $this->redirectRoute('dashboards.show', $dashboard);
    }

    public function startRename(int $id, string $name): void
    {
        $this->renamingId = $id;
        $this->renamingName = $name;
    }

    public function rename(): void
    {
        if ($this->renamingId === null) {
            return;
        }

        $this->validate(['renamingName' => ['required', 'string', 'max:100']]);

        $dashboard = Dashboard::withTrashed()->findOrFail($this->renamingId);

        abort_unless($this->mayManage($dashboard), 403);

        $dashboard->update(['name' => trim($this->renamingName)]);

        DashboardAudit::log($dashboard, 'renamed', ['name' => trim($this->renamingName)]);

        $this->reset(['renamingId', 'renamingName']);

        $this->dispatch('dashboard-list-updated');
    }

    /**
     * Archive (soft delete). Owners may archive their own dashboards;
     * superadmins may archive any, including system templates (restorable).
     */
    public function deleteDashboard(int $id): void
    {
        $dashboard = Dashboard::query()->findOrFail($id);

        abort_unless($this->mayManage($dashboard), 403);

        if ($dashboard->is_system && ! auth()->user()?->isSuperadmin()) {
            abort(403);
        }

        $dashboard->delete();

        DashboardAudit::log($dashboard, 'archived', ['name' => $dashboard->name]);

        $this->dispatch('dashboard-list-updated');
    }

    /** Restore an archived dashboard (owner or superadmin). */
    public function restoreDashboard(int $id): void
    {
        $dashboard = Dashboard::onlyTrashed()->findOrFail($id);

        abort_unless($this->mayManage($dashboard), 403);

        $dashboard->restore();

        DashboardAudit::log($dashboard, 'restored', ['name' => $dashboard->name]);

        $this->dispatch('dashboard-list-updated');
    }

    /** Permanently delete — superadmin only; cascades sources and shares. */
    public function forceDeleteDashboard(int $id): void
    {
        abort_unless(auth()->user()?->isSuperadmin(), 403);

        $dashboard = Dashboard::withTrashed()->findOrFail($id);

        DashboardAudit::log($dashboard, 'force_deleted', [
            'name' => $dashboard->name,
            'was_system' => $dashboard->is_system,
        ]);

        $dashboard->forceDelete();

        $this->dispatch('dashboard-list-updated');
    }

    /** Owner of the dashboard, or any superadmin. */
    private function mayManage(Dashboard $dashboard): bool
    {
        return $dashboard->owner_id === auth()->id()
            || (bool) auth()->user()?->isSuperadmin();
    }

    public function render(): View
    {
        $user = auth()->user();
        $isSuperadmin = (bool) $user?->isSuperadmin();

        // A pulled-but-unmigrated deploy should say what to run, not 500.
        if (! Schema::hasTable('dashboards')) {
            abort(503, 'Dashboard tables are missing on this server — run `php artisan migrate --force`.');
        }

        return view('livewire.dashboards-index', [
            'owned' => Dashboard::query()
                ->with('sources')
                ->where('owner_id', $user?->id)
                ->orderBy('name')
                ->get(),
            'shared' => Dashboard::query()
                ->with('sources')
                ->whereHas('shares', fn ($query) => $query->where('user_id', $user?->id))
                ->orderBy('name')
                ->get(),
            'system' => $isSuperadmin
                ? Dashboard::query()
                    ->with('sources')
                    ->where('is_system', true)
                    ->orderBy('name')
                    ->get()
                : collect(),
            // Superadmin-only global view: every dashboard (owned, shared or
            // private), searchable and capped. Never rendered for other roles.
            'all' => $isSuperadmin
                ? Dashboard::query()
                    ->with('sources')
                    ->with('owner:id,name')
                    ->when(trim($this->allSearch) !== '', function ($query): void {
                        $term = '%'.trim($this->allSearch).'%';
                        $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('description', 'like', $term));
                    })
                    ->orderBy('name')
                    ->limit(100)
                    ->get()
                : collect(),
            // Archived (soft-deleted) dashboards: the user's own, or every
            // archived dashboard for a superadmin.
            'archived' => Dashboard::onlyTrashed()
                ->with('sources')
                ->when(! $isSuperadmin, fn ($query) => $query->where('owner_id', $user?->id))
                ->orderBy('name')
                ->limit(100)
                ->get(),
            'isSuperadmin' => $isSuperadmin,
            'tableOptions' => $this->tableOptions(),
            'userOptions' => $isSuperadmin || $user !== null
                ? User::query()->whereKeyNot($user?->id)->orderBy('name')->get(['id', 'name', 'email'])
                : collect(),
        ])
            ->layout('layouts.dashboard')
            ->title('Dashboards');
    }

    /**
     * Selectable tables for the dashboard's required data source.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function tableOptions(): array
    {
        $catalog = app(TableCatalog::class);

        $options = collect(TableCatalog::CORE)
            ->map(fn (array $meta, string $key): array => ['key' => $key, 'label' => $meta['label']])
            ->values()
            ->all();

        $dynamic = \App\Models\DynamicTable::query()
            ->orderBy('name')
            ->get(['key', 'name'])
            ->map(fn ($table): array => ['key' => $table->key, 'label' => $table->name])
            ->all();

        return array_merge($options, $dynamic);
    }
}
