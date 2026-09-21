<?php

namespace App\Livewire;

use App\Models\Dashboard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

/**
 * Dashboards index: the user's own dashboards, dashboards shared with them,
 * and system dashboards. Owners can create, rename and delete; sharing and
 * data sources live on the dashboard page itself.
 */
class DashboardsIndex extends Component
{
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

        $dashboard = Dashboard::query()->findOrFail($this->renamingId);

        abort_unless($dashboard->owner_id === auth()->id(), 403);

        $dashboard->update(['name' => trim($this->renamingName)]);

        $this->reset(['renamingId', 'renamingName']);

        $this->dispatch('dashboard-list-updated');
    }

    public function deleteDashboard(int $id): void
    {
        $dashboard = Dashboard::query()->findOrFail($id);

        abort_unless($dashboard->owner_id === auth()->id() && ! $dashboard->is_system, 403);

        $dashboard->delete();

        $this->dispatch('dashboard-list-updated');
    }

    public function render(): View
    {
        $user = auth()->user();

        // A pulled-but-unmigrated deploy should say what to run, not 500.
        if (! Schema::hasTable('dashboards')) {
            abort(503, 'Dashboard tables are missing on this server — run `php artisan migrate --force`.');
        }

        return view('livewire.dashboards-index', [
            'owned' => Dashboard::query()
                ->where('owner_id', $user?->id)
                ->orderBy('name')
                ->get(),
            'shared' => Dashboard::query()
                ->whereHas('shares', fn ($query) => $query->where('user_id', $user?->id))
                ->orderBy('name')
                ->get(),
            'system' => Dashboard::query()
                ->where('is_system', true)
                ->orderBy('name')
                ->get(),
        ])
            ->layout('layouts.dashboard')
            ->title('Dashboards');
    }
}
