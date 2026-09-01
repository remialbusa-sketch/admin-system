<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Services\ProductDashboardService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Dashboard extends Component
{
    public string $region = 'All regions';

    public string $period = '12M';

    /** Set when the viewer's role pins the dashboard to their own region. */
    public bool $regionLocked = false;

    public function mount(): void
    {
        // Regional managers operate their own region: scope the dashboard to
        // it and lock the selector. National/president roles see everything.
        $user = auth()->user();

        if ($user?->role === UserRole::RegionalManager && filled($user->region)) {
            $this->region = $user->region;
            $this->regionLocked = true;
        }
    }

    public function updatedRegion(): void
    {
        // Region drives every widget; recompute happens in render().
    }

    public function updatedPeriod(): void
    {
        // Period drives the installation trend and its delta; recompute in render().
    }

    public function render(ProductDashboardService $service): View
    {
        $user = auth()->user();
        // Enforce the lock at render time too — a tampered property cannot
        // widen a regional manager's scope.
        $region = $this->regionLocked && $user?->role === UserRole::RegionalManager
            ? $user->region
            : $this->region;

        return view('livewire.dashboard', [
            'regionLocked' => $this->regionLocked,
            'periodOptions' => array_keys(ProductDashboardService::PERIODS),
            ...$service->summary($region === 'All regions' ? null : $region, $this->period),
        ])
            ->layout('layouts.dashboard')
            ->title('Home');
    }
}
