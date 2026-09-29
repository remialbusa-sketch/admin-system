<?php

namespace App\Livewire;

use App\Livewire\Concerns\WithCustomizableWidgets;
use App\Services\TspAnalyticsService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\DashboardLayoutEngine;
use App\Support\SystemDashboards;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class TspAnalytics extends Component
{
    use WithCustomizableWidgets;

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

    private const GRID = 'tsp';

    /** Name of the system dashboard row that backs this page. */
    private const CORE_NAME = 'TSP Analytics';

    private const METRIC_LABELS = [
        'filtered_reports' => 'Reports (filtered)',
        'distinct_tsps' => 'Distinct TSPs',
        'completion_rate' => 'Completion rate',
        'avg_repair_hours' => 'Avg repair time',
    ];

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
            ?? SystemDashboards::tspLayout();
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
        return 'TSP Records';
    }

    public function render(TspAnalyticsService $service, DashboardLayoutEngine $engine): View
    {
        $summary = $service->summary($this->region);
        $details = $service->details(
            $this->tspName,
            $this->dateFrom,
            $this->dateTo,
            $this->branch
        );

        $metrics = is_array($details['metrics'] ?? null) ? $details['metrics'] : [];
        $context = new DashboardContext($this->region, $this->period, $metrics, [], [], []);

        $state = $this->widgetGridState(self::GRID);
        $stored = $this->loadWidgetLayout(self::GRID);
        $layout = $state['customizing'] && $state['draftLayout'] !== []
            ? $state['draftLayout']
            : ($stored ?? SystemDashboards::tspLayout());

        return view('livewire.tsp-analytics', array_merge($summary, $details, [
            'tspOptions' => $service->tspOptions(),
            'branchOptions' => $service->branchOptions(),
            'tspGrid' => $this->widgetGridView(self::GRID, $layout, $context, $engine),
            'tspState' => $state,
            'tspCustomizing' => $state['customizing'],
            'canCustomizeTsp' => $this->canCustomizeWidgets(),
            'tspMetricLabels' => self::METRIC_LABELS,
            'tspMetricValues' => array_filter($metrics, 'is_numeric'),
        ]))
            ->layout('layouts.dashboard')
            ->title('TSP Analytics');
    }
}
