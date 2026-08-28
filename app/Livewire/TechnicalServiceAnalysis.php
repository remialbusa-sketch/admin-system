<?php

namespace App\Livewire;

use App\Services\TechnicalServiceAnalysisService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TechnicalServiceAnalysis extends Component
{
    public function render(TechnicalServiceAnalysisService $service): View
    {
        return view('livewire.technical-service-analysis', $service->summary())
            ->layout('layouts.dashboard')
            ->title('Technical Service Analysis');
    }
}
