<?php

namespace App\Livewire;

use App\Models\Dashboard;
use App\Support\DashboardAudit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
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

    public ?int $renamingId = null;

    public string $renamingName = '';

    public function createDashboard(): void
    {
        $user = auth()->user();

        abort_unless($user !== null, 403);

        $this->validate(['newName' => ['required', 'string', 'max:100']]);

        $dashboard = Dashboard::create([
            'owner_id' => $user->id,
            'name' => trim($this->newName),
            'layout' => config('dashboard.default_layout'),
        ]);

        // Every dashboard starts connected to the Product Database so the
        // shipped default widgets resolve; more tables can be connected on
        // the dashboard's Data sources panel.
        $dashboard->sources()->create([
            'table_key' => 'installed-products',
            'alias' => 'pdb',
            'position' => 0,
        ]);

        DashboardAudit::log($dashboard, 'created');

        $this->reset('newName');

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
            'system' => Dashboard::query()
                ->with('sources')
                ->where('is_system', true)
                ->orderBy('name')
                ->get(),
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
        ])
            ->layout('layouts.dashboard')
            ->title('Dashboards');
    }
}
