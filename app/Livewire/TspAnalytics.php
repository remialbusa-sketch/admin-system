<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class TspAnalytics extends Component
{
    public string $search = '';

    public string $period = 'Last 30 days';

    public string $region = 'All regions';

    public function render(): View
    {
        $regionalData = [
            ['region' => 'NCR', 'active' => 84, 'open' => 412, 'resolved' => '94.8%', 'response' => '2.1h'],
            ['region' => 'North Luzon', 'active' => 61, 'open' => 298, 'resolved' => '92.4%', 'response' => '2.8h'],
            ['region' => 'Visayas', 'active' => 55, 'open' => 240, 'resolved' => '89.7%', 'response' => '3.4h'],
            ['region' => 'Mindanao', 'active' => 48, 'open' => 201, 'resolved' => '91.2%', 'response' => '3.1h'],
        ];

        $visibleRegions = $this->region === 'All regions'
            ? $regionalData
            : collect($regionalData)->where('region', $this->region)->values()->all();

        $activeTsp = collect($visibleRegions)->sum('active');
        $openRecords = collect($visibleRegions)->sum('open');

        $kpis = [
            ['label' => 'Active TSPs', 'value' => number_format($activeTsp), 'context' => 'currently assigned', 'icon' => 'o-users', 'tone' => 'primary'],
            ['label' => 'Open records', 'value' => number_format($openRecords), 'context' => 'in selected scope', 'icon' => 'o-inbox-stack', 'tone' => 'info'],
            ['label' => 'SLA compliance', 'value' => '92.6%', 'context' => 'target is 90%', 'icon' => 'o-shield-check', 'tone' => 'success'],
            ['label' => 'Avg. response', 'value' => '2.8h', 'context' => 'down 18m from prior', 'icon' => 'o-clock', 'tone' => 'warning'],
        ];

        $trend = [
            ['label' => 'Week 1', 'resolved' => 68, 'sla' => 82],
            ['label' => 'Week 2', 'resolved' => 74, 'sla' => 86],
            ['label' => 'Week 3', 'resolved' => 81, 'sla' => 89],
            ['label' => 'Week 4', 'resolved' => 88, 'sla' => 93],
        ];

        return view('livewire.tsp-analytics', [
            'kpis' => $kpis,
            'regionalData' => $visibleRegions,
            'trend' => $trend,
        ])
            ->layout('layouts.dashboard')
            ->title('TSP Analytics');
    }
}
