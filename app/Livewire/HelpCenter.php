<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class HelpCenter extends Component
{
    public string $search = '';

    public function render(): View
    {
        return view('livewire.help-center')
            ->layout('layouts.dashboard')
            ->title('Help Center');
    }
}
