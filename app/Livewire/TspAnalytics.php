<?php

namespace App\Livewire;

use App\Livewire\Concerns\WithCustomizableWidgets;
use App\Models\PageWidgetLayout;
use App\Services\TspAnalyticsService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\DashboardLayoutEngine;
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

    private const METRIC_LABELS = [
        'active_tsps' => 'Active TSPs',
        'open_records' => 'Open records',
        'resolution_rate' => 'Resolution rate',
        'total_reports' => 'Total reports',
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
        return (bool) auth()->user()?->canEditRecords();
    }

    protected function loadWidgetLayout(string $grid): ?array
    {
        return PageWidgetLayout::query()
            ->where('user_id', auth()->id())
            ->where('page', self::GRID)
            ->first()?->layout;
    }

    protected function storeWidgetLayout(string $grid, array $layout): void
    {
        PageWidgetLayout::updateOrCreate(
            ['user_id' => auth()->id(), 'page' => self::GRID],
            ['layout' => $layout],
        );
    }

    protected function clearWidgetLayout(string $grid): void
    {
        PageWidgetLayout::query()
            ->where('user_id', auth()->id())
            ->where('page', self::GRID)
            ->delete();
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

    /**
     * Convert the curated KPI strip into real kpi_card widgets: same
     * numbers, fully customizable. Explicit opt-in, editors and up,
     * single-shot per user.
     */
    public function convertHeadlinesToWidgets(TspAnalyticsService $service): void
    {
        abort_unless($this->canCustomizeWidgets(), 403);

        $userId = auth()->id();

        if (! $userId || PageWidgetLayout::query()->where('user_id', $userId)->where('page', self::GRID)->exists()) {
            return;
        }

        $summary = $service->summary($this->region);

        $map = [
            'Active TSPs' => ['metric' => 'active_tsps'],
            'Open records' => ['metric' => 'open_records'],
            'Resolution rate' => ['metric' => 'resolution_rate', 'suffix' => '%', 'decimals' => 1],
            'Total reports' => ['metric' => 'total_reports'],
        ];

        $this->seedWidgetDraft(self::GRID, $this->buildKpiWidgets($summary['kpis'], $map));
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

        $metrics = is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];
        $context = new DashboardContext($this->region, $this->period, $metrics, [], [], []);

        $state = $this->widgetGridState(self::GRID);
        $stored = $this->loadWidgetLayout(self::GRID);
        $hasWidgets = $stored !== null;
        $layout = $state['customizing'] && $state['draftLayout'] !== []
            ? $state['draftLayout']
            : ($stored ?? ['version' => 1, 'widgets' => []]);

        return view('livewire.tsp-analytics', array_merge($summary, $details, [
            'tspOptions' => $service->tspOptions(),
            'branchOptions' => $service->branchOptions(),
            'tspGrid' => $this->widgetGridView(self::GRID, $layout, $context, $engine),
            'hasTspWidgets' => $hasWidgets,
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
