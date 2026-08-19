<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class Dashboard extends Component
{
    /**
     * Search term bound to the top navbar search box (bound in the shared layout).
     */
    public string $search = '';

    public function render(): View
    {
        return view('livewire.dashboard')
            ->layout('layouts.dashboard')
            ->title('Dashboard');
    }
}
