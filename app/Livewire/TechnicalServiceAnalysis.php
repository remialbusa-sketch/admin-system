<?php

namespace App\Livewire;

use App\Livewire\Concerns\WithCustomizableWidgets;
use App\Services\DashboardColumnService;
use App\Services\TechnicalServiceAnalysisService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\DashboardLayoutEngine;
use App\Support\Dashboard\TechnicalServiceReference;
use App\Support\Dashboard\WidgetPresets;
use App\Support\SystemDashboards;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class TechnicalServiceAnalysis extends Component
{
    use WithCustomizableWidgets;

    public string $period = '30D';

    #[Url(as: 'slicers')]
    public array $slicerSelections = [];

    #[Url(as: 'fields')]
    public array $analysisColumns = [];

    private ?array $referenceLayoutCache = null;

    private function referenceLayout(): array
    {
        return $this->referenceLayoutCache ??= TechnicalServiceReference::layout($this->analysisColumns);
    }

    public function updatedAnalysisColumns(): void
    {
        $this->referenceLayoutCache = null;
        $this->slicerSelections = [];
    }

    private function analysisFilters(): array
    {
        $filters = [];
        $columns = app(DashboardColumnService::class);
        foreach ($this->referenceLayout()['widgets'] as $widget) {
            $values = $this->slicerSelections[$widget['id']] ?? [];
            if ($widget['type'] !== 'slicer' || ! is_array($values) || $values === [] || empty($widget['props']['column'])) {
                continue;
            }
            [$table, $key] = $columns->field($widget['props']['column'], TechnicalServiceReference::SOURCES);
            $values = array_slice(array_values(array_unique(array_filter($values, fn ($value) => is_string($value) && mb_strlen($value) <= 1000))), 0, 100);
            if ($values !== []) {
                $filters[$widget['id']] = ['table_key' => $table, 'key' => $key, 'mode' => $widget['props']['mode'], 'values' => $values];
            }
        }

        return ['slicers' => $filters];
    }

    public function toggleSlicerValue(string $id, string $value): void
    {
        $widget = collect($this->referenceLayout()['widgets'])->firstWhere('id', $id);
        abort_unless($widget && $widget['type'] === 'slicer' && ! empty($widget['props']['column']) && mb_strlen($value) <= 1000, 422);
        $selected = $this->analysisFilters()['slicers'][$id]['values'] ?? [];
        $this->slicerSelections[$id] = in_array($value, $selected, true)
            ? array_values(array_diff($selected, [$value])) : array_slice([...$selected, $value], -100);
    }

    public function clearSlicer(string $id): void
    {
        unset($this->slicerSelections[$id]);
    }

    public function clearAnalysisFilters(): void
    {
        $this->slicerSelections = [];
    }

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
        return [
            'status' => 'Report status mix',
            'trend' => 'Completions trend',
            'by_tsp' => 'Workload by TSP',
            'brands' => 'Most serviced brands',
        ];
    }

    protected function widgetScopeLabel(string $grid): string
    {
        return 'Technical Reports';
    }

    public function render(TechnicalServiceAnalysisService $service, DashboardLayoutEngine $engine): View
    {
        $filters = $this->analysisFilters();
        $summary = $service->summary($this->period, $filters);
        $metrics = is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];
        // Chart/table widgets read the retired hardcoded sections' queries
        // as named datasets (same rows, same numbers).
        $context = new DashboardContext('All regions', $this->period, $metrics, [
            'status' => $summary['statusDonut'] ?? [],
            'trend' => $summary['trend'] ?? [],
            'by_tsp' => $summary['byTsp'] ?? [],
            'brands' => $summary['brandDonut'] ?? [],
        ], ['tr' => ['table_key' => 'technical-reports']], $filters);

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
            'tsaPresets' => WidgetPresets::forPage('tsa'),
            'referenceGrid' => $engine->build($this->referenceLayout(), $context),
            'analysisColumnOptions' => array_filter(app(DashboardColumnService::class)->columns('technical-reports'), fn ($column) => ! isset($column['merged_into'])),
            'analysisFilters' => $filters['slicers'],
        ])
            ->layout('layouts.dashboard')
            ->title('Technical Service Analysis');
    }
}
