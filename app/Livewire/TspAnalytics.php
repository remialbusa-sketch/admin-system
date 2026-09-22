<?php

namespace App\Livewire;

use App\Services\TspAnalyticsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class TspAnalytics extends Component
{
    #[Url]
    public string $period = 'Last 30 days';

    #[Url]
    public string $region = 'All regions';

    #[Url]
    public string $tspName = 'All TSPs';

    #[Url]
    public string $branch = 'All branches';

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
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
