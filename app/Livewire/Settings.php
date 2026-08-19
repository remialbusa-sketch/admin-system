<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class Settings extends Component
{
    public string $search = '';

    public function render(): View
    {
        return view('livewire.settings')
            ->layout('layouts.dashboard')
            ->title('Settings');
    }
}
