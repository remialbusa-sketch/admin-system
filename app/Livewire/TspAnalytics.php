<?php

namespace App\Livewire;

use App\Services\TspAnalyticsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TspAnalytics extends Component
{
    public string $period = 'Last 30 days';

    public string $region = 'All regions';

    public string $tspName = 'All TSPs';

    public string $branch = 'All branches';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public function applyPeriod(): void
    {
        $days = match ($this->period) {
            'Last 7 days' => 7,
            'Last 30 days' => 30,
            'Last 90 days' => 90,
            default => 30,
        };

        $this->dateTo = now()->toDateString();
        $this->dateFrom = now()->subDays($days)->toDateString();
    }

    public function render(TspAnalyticsService $service): View
    {
        $summary = $service->summary($this->region);
        $details = $service->details(
            $this->tspName,
            $this->dateFrom,
            $this->dateTo,
            $this->branch
        );

        return view('livewire.tsp-analytics', array_merge($summary, $details, [
            'tspOptions' => $service->tspOptions(),
            'branchOptions' => $service->branchOptions(),
        ]))
            ->layout('layouts.dashboard')
            ->title('TSP Analytics');
    }
}
