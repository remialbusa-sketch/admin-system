<?php

namespace App\Livewire;

use App\Livewire\Concerns\WithCustomizableWidgets;
use App\Models\PageWidgetLayout;
use App\Services\TechnicalServiceAnalysisService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\DashboardLayoutEngine;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TechnicalServiceAnalysis extends Component
{
    use WithCustomizableWidgets;

    public string $period = '30D';

    private const GRID = 'tsa';

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
        return 'Technical Reports';
    }

    /**
     * Convert the curated headline + supporting cards into real kpi_card
     * widgets: same numbers, fully customizable. Explicit opt-in, editors
     * and up, single-shot per user.
     */
    public function convertHeadlinesToWidgets(TechnicalServiceAnalysisService $service): void
    {
        abort_unless($this->canCustomizeWidgets(), 403);

        $userId = auth()->id();

        if (! $userId || PageWidgetLayout::query()->where('user_id', $userId)->where('page', self::GRID)->exists()) {
            return;
        }

        $summary = $service->summary($this->period);
        $days = $summary['trendDays'] ?? 30;

        $map = [
            'Technical reports' => ['metric' => 'reports_total'],
            'Completed' => ['metric' => 'completed'],
            'Assigned TSP' => ['metric' => 'assigned_tsp'],
            'Avg repair time' => ['metric' => 'avg_repair_hours', 'suffix' => 'h', 'decimals' => 1],
            'Avg response time' => ['metric' => 'avg_response_hours', 'suffix' => 'h', 'decimals' => 1],
            'Completed · '.$days.'d' => ['metric' => 'window_completed'],
            'Unassigned reports' => ['metric' => 'unassigned'],
        ];

        $this->seedWidgetDraft(self::GRID, $this->buildKpiWidgets(
            [...$summary['kpis'], ...$summary['secondary']],
            $map,
        ));
    }

    public function render(TechnicalServiceAnalysisService $service, DashboardLayoutEngine $engine): View
    {
        $summary = $service->summary($this->period);
        $metrics = is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];
        $context = new DashboardContext('All regions', $this->period, $metrics, [], [], []);

        $state = $this->widgetGridState(self::GRID);
        $stored = $this->loadWidgetLayout(self::GRID);
        $hasWidgets = $stored !== null;
        $layout = $state['customizing'] && $state['draftLayout'] !== []
            ? $state['draftLayout']
            : ($stored ?? ['version' => 1, 'widgets' => []]);

        return view('livewire.technical-service-analysis', [
            'periodOptions' => array_keys(TechnicalServiceAnalysisService::PERIODS),
            ...$summary,
            'tsaGrid' => $this->widgetGridView(self::GRID, $layout, $context, $engine),
            'hasTsaWidgets' => $hasWidgets,
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
