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
use Illuminate\Validation\ValidationException;
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

    private ?array $resolvedLayoutCache = null;

    /**
     * The one layout this page renders: the stored system-row layout (back-fill
     * of the reference widgets happens there for layouts saved before the
     * merge) or the shipped default with the reference widgets appended.
     * Chart-fields overrides are applied in both cases.
     */
    private function resolvedLayout(): array
    {
        if (is_array($this->resolvedLayoutCache)) {
            return $this->resolvedLayoutCache;
        }

        $raw = SystemDashboards::coreRow(self::CORE_NAME)?->layout;
        $base = is_array($raw) ? $raw : SystemDashboards::tsaLayout($this->period);

        return $this->resolvedLayoutCache = TechnicalServiceReference::merge($base, $this->analysisColumns);
    }

    public function updatedAnalysisColumns(): void
    {
        $this->resolvedLayoutCache = null;
        $this->slicerSelections = [];
    }

    private function analysisFilters(): array
    {
        $filters = [];
        $columns = app(DashboardColumnService::class);
        foreach ($this->resolvedLayout()['widgets'] as $widget) {
            $values = $this->slicerSelections[$widget['id']] ?? [];
            if ($widget['type'] !== 'slicer' || ! is_array($values) || $values === [] || empty($widget['props']['column'])) {
                continue;
            }
            try {
                [$table, $key] = $columns->field($widget['props']['column'], TechnicalServiceReference::SOURCES);
            } catch (ValidationException) {
                continue; // A stored column that no longer exists filters nothing.
            }
            $values = array_slice(array_values(array_unique(array_filter($values, fn ($value) => is_string($value) && mb_strlen($value) <= 1000))), 0, 100);
            if ($values !== []) {
                $filters[$widget['id']] = ['table_key' => $table, 'key' => $key, 'mode' => $widget['props']['mode'], 'values' => $values,
                    'label' => (string) ($widget['props']['label'] ?? $widget['id'])];
            }
        }

        return ['slicers' => $filters];
    }

    public function toggleSlicerValue(string $id, string $value): void
    {
        $widget = collect($this->resolvedLayout()['widgets'])->firstWhere('id', $id);
        abort_unless($widget && $widget['type'] === 'slicer' && ! empty($widget['props']['column']) && mb_strlen($value) <= 1000, 422);
        try {
            app(DashboardColumnService::class)->field($widget['props']['column'], TechnicalServiceReference::SOURCES);
        } catch (ValidationException) {
            abort(422, 'This slicer column is no longer available.');
        }
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
        // One shared layout per core page; the shipped default (with the
        // reference set appended) applies until an admin saves (and after
        // Reset).
        return $this->resolvedLayout();
    }

    protected function storeWidgetLayout(string $grid, array $layout): void
    {
        // Stamping the saved layouts tells resolvedLayout() the merge done:
        // later changes are the admin's — their deletions persist, the
        // reference widgets are never re-appended against their wishes. The
        // version stamp is what lets a NEW reference widget land exactly once
        // on an already-saved layout (TechnicalServiceReference::merge).
        $layout['reference_marked'] = true;
        $layout['version'] = TechnicalServiceReference::LAYOUT_VERSION;
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

    /**
     * Column charts and slicers only come from the shipped reference set on
     * this page, so they badge as reference-sheet widgets in edit mode.
     *
     * @param  array<string, mixed>  $widget
     * @return array{label: string, kind: string}|null
     */
    protected function widgetProvenanceOverrideFor(string $grid, array $widget): ?array
    {
        if (in_array($widget['type'] ?? '', ['column_chart', 'slicer'], true)) {
            return ['label' => 'Reference sheet', 'kind' => $this->widgetScopeKind($grid)];
        }

        return null;
    }

    /** The only connected source for the settings gear's column pickers. */
    protected function widgetColumnSources(string $grid): array
    {
        return TechnicalServiceReference::SOURCES;
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
        $layout = $state['customizing'] && $state['draftLayout'] !== []
            ? $state['draftLayout']
            : $this->resolvedLayout();

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
            'analysisColumnOptions' => array_filter(app(DashboardColumnService::class)->columns('technical-reports'), fn ($column) => ! isset($column['merged_into'])),
            'analysisFilters' => $filters['slicers'],
        ])
            ->layout('layouts.dashboard')
            ->title('Technical Service Analysis');
    }
}
