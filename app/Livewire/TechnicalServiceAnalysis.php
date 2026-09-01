<?php

namespace App\Livewire;

use App\Services\TechnicalServiceAnalysisService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TechnicalServiceAnalysis extends Component
{
    public string $period = '30D';

    public function updatedPeriod(): void
    {
        // Period drives the completion trend and window metrics; recompute in render().
    }

    public function render(TechnicalServiceAnalysisService $service): View
    {
        return view('livewire.technical-service-analysis', [
            'periodOptions' => array_keys(TechnicalServiceAnalysisService::PERIODS),
            ...$service->summary($this->period),
        ])
            ->layout('layouts.dashboard')
            ->title('Technical Service Analysis');
    }
}
