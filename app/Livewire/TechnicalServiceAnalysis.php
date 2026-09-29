<?php

namespace App\Livewire;

use App\Livewire\Concerns\WithCustomizableWidgets;
use App\Services\TechnicalServiceAnalysisService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\DashboardLayoutEngine;
use App\Support\SystemDashboards;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TechnicalServiceAnalysis extends Component
{
    use WithCustomizableWidgets;

    public string $period = '30D';

    private const GRID = 'tsa';

    /** Name of the system dashboard row that backs this page. */
    private const CORE_NAME = 'Technical Service Analysis';

    private const METRIC_LABELS = [
        'reports_total' => 'Technical reports',
        'completed' => 'Completed',
        'assigned_tsp' => 'Assigned TSP',
        'avg_repair_hours' => 'Avg repair time',
        'avg_response_hours' => 'Avg response time',
        'window_completed' => 'Completed (window)',
        'unassigned' => 'Unassigned reports',
    ];

    public function updatedPeriod(): void
    {
        // Period drives the completion trend and window metrics; recompute in render().
    }

    protected function canCustomizeWidgets(): bool
    {
        // Core dashboards are shared by everyone: only superadmin/admin may
        // edit the layout (editors are excluded by design).
        return (bool) auth()->user()?->canEditCoreDashboards();
    }

    protected function loadWidgetLayout(string $grid): ?array
    {
        // One shared layout per core page, stored on its system row; the
        // shipped default applies until an admin saves (and after Reset).
        return SystemDashboards::coreRow(self::CORE_NAME)?->layout
            ?? SystemDashboards::tsaLayout($this->period);
    }

    protected function storeWidgetLayout(string $grid, array $layout): void
    {
        SystemDashboards::storeLayout(self::CORE_NAME, $layout);
    }

    protected function clearWidgetLayout(string $grid): void
    {
        SystemDashboards::storeLayout(self::CORE_NAME, null);
    }

    protected function widgetMetricVocabulary(string $grid): array
    {
        return self::METRIC_LABELS;
    }

    protected function widgetDatasetVocabulary(string $grid): array
    {
        return [];
    }

    protected function widgetScopeLabel(string $grid): string
    {
        return 'Technical Reports';
    }

    public function render(TechnicalServiceAnalysisService $service, DashboardLayoutEngine $engine): View
    {
        $summary = $service->summary($this->period);
        $metrics = is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];
        $context = new DashboardContext('All regions', $this->period, $metrics, [], [], []);

        $state = $this->widgetGridState(self::GRID);
        $stored = $this->loadWidgetLayout(self::GRID);
        $layout = $state['customizing'] && $state['draftLayout'] !== []
            ? $state['draftLayout']
            : ($stored ?? SystemDashboards::tsaLayout($this->period));

        return view('livewire.technical-service-analysis', [
            'periodOptions' => array_keys(TechnicalServiceAnalysisService::PERIODS),
            ...$summary,
            'tsaGrid' => $this->widgetGridView(self::GRID, $layout, $context, $engine),
            'tsaState' => $state,
            'tsaCustomizing' => $state['customizing'],
            'canCustomizeTsa' => $this->canCustomizeWidgets(),
            'tsaMetricLabels' => self::METRIC_LABELS,
            'tsaMetricValues' => array_filter($metrics, 'is_numeric'),
        ])
            ->layout('layouts.dashboard')
            ->title('Technical Service Analysis');
    }
}
