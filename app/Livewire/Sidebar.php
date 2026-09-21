<?php

namespace App\Livewire;

use App\Models\Dashboard;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The left-hand navigation chrome, promoted to a Livewire component so it can
 * react to real-time changes — table pinning and the user's dashboard list.
 *
 * TablesList dispatches `table-pins-updated` when a table is pinned/unpinned;
 * DashboardsIndex dispatches `dashboard-list-updated` when a dashboard is
 * renamed or deleted. The sidebar re-reads both from the DB in render().
 *
 * The rendered markup lives in resources/views/components/admin/sidebar.blade.php
 * (kept there so the collapse/mobile Alpine behaviour and responsive HTML are
 * unchanged).
 */
class Sidebar extends Component
{
    #[On('table-pins-updated')]
    public function refreshPins(): void
    {
        // State is re-read from the DB in render(); nothing to cache here.
    }

    #[On('dashboard-list-updated')]
    public function refreshDashboards(): void
    {
        // Same: render() re-reads the dashboard list.
    }

    public function render()
    {
        $user = auth()->user();

        $dashboards = $user === null
            ? collect()
            : Dashboard::query()
                ->where(fn ($query) => $query
                    ->where('owner_id', $user->id)
                    ->orWhereHas('shares', fn ($share) => $share->where('user_id', $user->id)))
                ->orderBy('name')
                ->limit(10)
                ->get();

        return view('components.admin.sidebar', [
            'dashboards' => $dashboards,
        ]);
    }
}
