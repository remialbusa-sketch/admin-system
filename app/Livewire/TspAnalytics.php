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
        return (bool) auth()->user()?->canEditRecords();
    }

    protected function loadWidgetLayout(string $grid): ?array
    {
        return PageWidgetLayout::query()
            ->where('user_id', auth()->id())
            ->where('page', self::GRID)
            ->first()?->layout
            ?? $this->defaultTspWidgets();
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
     * The shipped TSP cards as real widgets: same cards, same numbers,
     * fully customizable. Used until the user saves a personal layout
     * (Reset returns here).
     *
     * @return array{version: int, widgets: array<int, array<string, mixed>>}
     */
    private function defaultTspWidgets(): array
    {
        return ['version' => 1, 'widgets' => [
            [
                'id' => 'tsp-filtered', 'type' => 'supporting_kpi', 'w' => 3, 'h' => 1,
                'props' => [
                    'label' => 'Reports (filtered)', 'metric' => 'filtered_reports',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'matching filters',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => '', 'icon' => 'o-document-text', 'tone' => 'primary',
                ],
            ],
            [
                'id' => 'tsp-distinct', 'type' => 'supporting_kpi', 'w' => 3, 'h' => 1,
                'props' => [
                    'label' => 'Distinct TSPs', 'metric' => 'distinct_tsps',
                    'formula' => '', 'suffix' => '', 'decimals' => 0,
                    'context' => 'in current scope',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => '', 'icon' => 'o-users', 'tone' => 'info',
                ],
            ],
            [
                'id' => 'tsp-completion', 'type' => 'supporting_kpi', 'w' => 3, 'h' => 1,
                'props' => [
                    'label' => 'Completion rate', 'metric' => 'completion_rate',
                    'formula' => '', 'suffix' => '%', 'decimals' => 1,
                    'context' => 'completed reports',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => '', 'icon' => 'o-shield-check', 'tone' => 'success',
                ],
            ],
            [
                'id' => 'tsp-repair', 'type' => 'supporting_kpi', 'w' => 3, 'h' => 1,
                'props' => [
                    'label' => 'Avg repair time', 'metric' => 'avg_repair_hours',
                    'formula' => '', 'suffix' => 'h', 'decimals' => 2,
                    'context' => 'per report',
                    'context_metric' => '', 'context_prefix' => '', 'context_suffix' => '', 'context_decimals' => 0,
                    'href' => '', 'icon' => 'o-clock', 'tone' => 'warning',
                ],
            ],
        ]];
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
            : ($stored ?? $this->defaultTspWidgets());

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
