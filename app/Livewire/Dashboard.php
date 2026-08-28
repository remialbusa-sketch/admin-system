<?php

namespace App\Livewire;

use App\Services\PresidentDashboardService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Dashboard extends Component
{
    public string $region = 'All regions';

    public function updatedRegion(): void
    {
        // Region drives every widget; recompute happens in render().
    }

    public function render(PresidentDashboardService $service): View
    {
        return view('livewire.dashboard', $service->summary($this->region === 'All regions' ? null : $this->region))
            ->layout('layouts.dashboard')
            ->title('Home');
    }
}