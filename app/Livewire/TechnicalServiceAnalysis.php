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
            ->first()?->layout
            ?? $this->defaultTsaWidgets();
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
     * The shipped TSA cards as real widgets: same cards, same numbers,
     * fully customizable. Used until the user saves a personal layout
     * (Reset returns here).
     *
     * @return array{version: int, widgets: array<int, array<string, mixed>>}
     */
    private function defaultTsaWidgets(): array
    {
        $days = TechnicalServiceAnalysisService::PERIODS[$this->period] ?? 30;
        $trendFrom = now()->today()->subDays($days - 1)->toDateString();

        return ['version' => 1, 'widgets' => [
            [
                'id' => 'tsa-reports', 'type' => 'headline_kpi', 'w' => 4, 'h' => 3,
                'props' => [
                    'label' => 'Technical reports', 'variant' => 'lead', 'metric' => 'reports_total',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'imported service reports',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'caption' => 'reports on file', 'href' => route('technical-reports'),
                ],
            ],
            [
                'id' => 'tsa-completed', 'type' => 'headline_kpi', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Completed', 'variant' => 'card', 'metric' => 'completed',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'have a completion time',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'caption' => '', 'href' => route('technical-reports', ['completed' => 'any']),
                ],
            ],
            [
                'id' => 'tsa-assigned', 'type' => 'headline_kpi', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Assigned TSP', 'variant' => 'card', 'metric' => 'assigned_tsp',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => '', 'context_metric' => 'unassigned', 'context_prefix' => '',
                    'context_suffix' => ' unassigned', 'context_decimals' => 0,
                    'caption' => '', 'href' => route('technical-reports', ['assigned' => '1']),
                ],
            ],
            [
                'id' => 'tsa-repair', 'type' => 'headline_kpi', 'w' => 4, 'h' => 2,
                'props' => [
                    'label' => 'Avg repair time', 'variant' => 'card', 'metric' => 'avg_repair_hours',
                    'formula' => '', 'suffix' => 'h', 'decimals' => 1,
                    'context' => 'mean per report',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'caption' => '', 'href' => route('technical-reports'),
                ],
            ],
            [
                'id' => 'tsa-response', 'type' => 'supporting_kpi', 'w' => 4, 'h' => 1,
                'props' => [
                    'label' => 'Avg response time', 'metric' => 'avg_response_hours',
                    'formula' => '', 'suffix' => 'h', 'decimals' => 1,
                    'context' => 'from report data',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => route('technical-reports'), 'icon' => '', 'tone' => 'primary',
                ],
            ],
            [
                'id' => 'tsa-window', 'type' => 'supporting_kpi', 'w' => 4, 'h' => 1,
                'props' => [
                    'label' => 'Completed · {period}', 'metric' => 'window_completed',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'in the selected window',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => route('technical-reports', ['completed_from' => $trendFrom, 'completed_to' => now()->toDateString()]),
                    'icon' => '', 'tone' => 'success',
                ],
            ],
            [
                'id' => 'tsa-unassigned', 'type' => 'supporting_kpi', 'w' => 4, 'h' => 1,
                'props' => [
                    'label' => 'Unassigned reports', 'metric' => 'unassigned',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'no TSP on record',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => route('technical-reports', ['assigned' => '0']), 'icon' => '', 'tone' => 'error',
                ],
            ],
        ]];
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
            : ($stored ?? $this->defaultTsaWidgets());

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
