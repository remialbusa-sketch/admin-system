<?php

namespace App\Livewire;

use App\Models\Dashboard;
use App\Models\DashboardSource;
use App\Models\DynamicTable;
use App\Services\TableAggregationService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\GridLayoutNormalizer;
use App\Support\Dashboard\WidgetRegistry;
use App\Support\DashboardAudit;
use App\Support\TableCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Create a data visualization" wizard: pick a table (pre-selected when
 * launched from a table page), pick a visual type, pick what it measures, and
 * add the single widget to a dashboard you own or can edit.
 *
 * The wizard never writes widget settings by hand beyond the factory's own
 * defaults + the chosen dataset/metric; the generated props go through the
 * same GridLayoutNormalizer as the dashboard editor.
 */
class WidgetWizard extends Component
{
    /** Widget types the wizard can generate from a dataset. */
    private const DATASET_WIDGETS = ['line_chart', 'bar_chart', 'donut_chart', 'table'];

    /** Widget types the wizard can generate from a scalar metric. */
    private const METRIC_WIDGETS = ['kpi_card', 'stat', 'gauge'];

    /** URL-bound (?table=…) so table pages can deep-link into the wizard. */
    #[Url(as: 'table')]
    public string $tableKey = '';

    /** URL-bound (?dashboard=…) so a dashboard can pre-select itself. */
    #[Url(as: 'dashboard')]
    public ?int $dashboardId = null;

    public string $newDashboardName = '';

    public string $widgetType = '';

    public string $dataset = '';

    public string $metric = '';

    public string $title = '';

    public function mount(?string $table = null, ?int $dashboard = null): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        // Breadcrumbs: a bare 500 in production becomes a one-line diagnosis
        // (which step failed) without needing APP_DEBUG.
        Log::info('widgetWizard.enter', [
            'table' => $table,
            'dashboard' => $dashboard,
            'user_id' => $user->id,
        ]);

        // The dashboard subsystem needs its tables; a pulled-but-unmigrated
        // deploy should say so instead of returning a bare 500.
        if (! Schema::hasTable('dashboards')) {
            abort(503, 'Dashboard tables are missing on this server — run `php artisan migrate --force`.');
        }

        // #[Url] already filled these from the query string on a real request;
        // explicit mount arguments (tests, route params) win when provided.
        if ($table !== null && $table !== '') {
            $this->tableKey = $table;
        }

        if ($dashboard !== null) {
            $this->dashboardId = $dashboard;
        }

        // A dashboard in the URL must be one this user may edit — never leak
        // or write into someone else's dashboard.
        if ($this->dashboardId !== null && ! $this->editableDashboards()->contains('id', $this->dashboardId)) {
            abort(403);
        }

        $this->dashboardId ??= $this->editableDashboards()->first()?->id;

        try {
            $this->applySuggestion();
        } catch (\Throwable $exception) {
            // Suggestions are best-effort; never block the wizard page.
            report($exception);

            Log::error('widgetWizard.suggestFailed', [
                'table' => $this->tableKey,
                'error' => $exception->getMessage(),
            ]);
        }

        Log::info('widgetWizard.ready', [
            'table' => $this->tableKey,
            'dashboard' => $this->dashboardId,
            'widget_type' => $this->widgetType,
        ]);
    }

    /**
     * The dashboards this user may add widgets to: owned or shared edit, and
     * every dashboard for a superadmin. Cached per request (mount + render
     * would otherwise query three times).
     */
    private ?Collection $editableDashboardsCache = null;

    private function editableDashboards(): Collection
    {
        if ($this->editableDashboardsCache !== null) {
            return $this->editableDashboardsCache;
        }

        $user = auth()->user();

        if ($user?->isSuperadmin()) {
            return $this->editableDashboardsCache = Dashboard::query()->orderBy('name')->get();
        }

        return $this->editableDashboardsCache = Dashboard::query()
            ->where(function ($query) use ($user): void {
                $query->where('owner_id', $user->id)
                    ->orWhereHas('shares', fn ($share) => $share->where('user_id', $user->id)->where('permission', 'edit'));
            })
            ->orderBy('name')
            ->get();
    }

    public function updatedTableKey(): void
    {
        $this->applySuggestion();
    }

    public function updatedWidgetType(): void
    {
        $this->applySuggestion(keepType: true);
    }

    /**
     * Choose a sensible widget type, dataset/metric and title for the table.
     */
    private function applySuggestion(bool $keepType = false): void
    {
        $datasets = $this->availableDatasets();
        $metrics = $this->availableMetrics();

        if (! $keepType || $this->widgetType === '') {
            if ($datasets !== [] && array_key_exists('by_month', $datasets)) {
                $this->widgetType = 'line_chart';
            } elseif ($datasets !== []) {
                $this->widgetType = 'donut_chart';
            } elseif ($metrics !== []) {
                $this->widgetType = 'kpi_card';
            } else {
                $this->widgetType = '';
            }
        }

        if ($this->isDatasetWidget($this->widgetType)) {
            if (! array_key_exists($this->dataset, $datasets)) {
                // Trend charts read the monthly dataset; everything else takes
                // the first categorical dataset available.
                $this->dataset = $this->widgetType === 'line_chart' && array_key_exists('by_month', $datasets)
                    ? 'by_month'
                    : (array_key_first($datasets) ?? '');
            }
        } else {
            $this->dataset = '';
        }

        if ($this->isMetricWidget($this->widgetType)) {
            if (! array_key_exists($this->metric, $metrics)) {
                $this->metric = array_key_first($metrics) ?? '';
            }
        } else {
            $this->metric = '';
        }

        if ($this->title === '') {
            $this->title = $this->defaultTitle();
        }
    }

    private function defaultTitle(): string
    {
        $table = $this->tableLabel();

        return match (true) {
            $this->isDatasetWidget($this->widgetType) => $table.' · '.ucfirst(str_replace('_', ' ', Str::afterLast($this->dataset, '.'))),
            $this->isMetricWidget($this->widgetType) => $table.' · '.ucfirst(str_replace('_', ' ', Str::afterLast($this->metric, '.'))),
            default => $table,
        };
    }

    private function isDatasetWidget(string $type): bool
    {
        return in_array($type, self::DATASET_WIDGETS, true);
    }

    private function isMetricWidget(string $type): bool
    {
        return in_array($type, self::METRIC_WIDGETS, true);
    }

    /** @return array<string, string> dataset key => label */
    public function availableDatasets(): array
    {
        if ($this->tableKey === '') {
            return [];
        }

        $summary = app(TableAggregationService::class)->summary($this->tableKey);

        return collect(array_keys($summary['datasets']))
            ->mapWithKeys(fn (string $key): array => [$key => ucfirst(str_replace('_', ' ', $key))])
            ->all();
    }

    /** @return array<string, string> metric key => label */
    public function availableMetrics(): array
    {
        if ($this->tableKey === '') {
            return [];
        }

        $summary = app(TableAggregationService::class)->summary($this->tableKey);

        return collect(array_keys($summary['metrics']))
            ->mapWithKeys(fn (string $key): array => [$key => ucfirst(str_replace('_', ' ', $key))])
            ->all();
    }

    private function tableLabel(): string
    {
        return app(TableCatalog::class)->resolve($this->tableKey)['label'] ?? $this->tableKey;
    }

    /**
     * The alias this table will use on the selected dashboard (existing source
     * alias, or the alias that will be created on save).
     */
    private function resolvedAlias(): ?string
    {
        $dashboard = $this->selectedDashboard();

        if ($dashboard === null || $this->tableKey === '') {
            return null;
        }

        $existing = $dashboard->sources()->where('table_key', $this->tableKey)->first();

        return $existing?->alias ?? DashboardSource::uniqueAliasFor($dashboard, Str::slug($this->tableKey, '_'));
    }

    private function selectedDashboard(): ?Dashboard
    {
        if ($this->dashboardId === null) {
            return null;
        }

        $dashboard = Dashboard::query()->find($this->dashboardId);

        return $dashboard?->canBeEditedBy(auth()->user()) ? $dashboard : null;
    }

    /**
     * The props for the chosen widget, with the dataset/metric namespaced to
     * the dashboard's source alias.
     *
     * @return array<string, mixed>
     */
    private function buildProps(?string $alias): array
    {
        $definition = app(WidgetRegistry::class)->make($this->widgetType)->definition();
        $props = $definition['defaultProps'] ?? [];
        $props['label'] = $this->title !== '' ? $this->title : ($definition['title'] ?? 'Visualization');
        $props['context'] = $this->tableLabel().' · live';

        if ($alias !== null && $this->isDatasetWidget($this->widgetType)) {
            $props['dataset'] = $alias.'.'.$this->dataset;
            $props['label_key'] = 'label';
            $props['value_key'] = str_ends_with($this->dataset, 'by_month') ? 'count' : 'value';
        } elseif ($alias !== null && $this->isMetricWidget($this->widgetType)) {
            $props['metric'] = $alias.'.'.$this->metric;
        }

        // Metric cards drill into the table they measure.
        if ($this->isMetricWidget($this->widgetType) && $this->tableKey !== '') {
            $resolved = app(TableCatalog::class)->resolve($this->tableKey);

            if ($resolved !== null) {
                $props['href'] = isset($resolved['params'])
                    ? route($resolved['route'], $resolved['params'])
                    : route($resolved['route']);
            }
        }

        return $props;
    }

    public function create(): void
    {
        $user = auth()->user();

        $this->validate([
            'tableKey' => ['required', 'string', 'max:64'],
            'widgetType' => ['required', 'string'],
            'title' => ['required', 'string', 'max:80'],
            'newDashboardName' => ['nullable', 'string', 'max:100'],
        ]);

        if (! app(TableCatalog::class)->exists($this->tableKey)) {
            $this->addError('tableKey', 'That table does not exist.');

            return;
        }

        if (! in_array($this->widgetType, [...self::DATASET_WIDGETS, ...self::METRIC_WIDGETS], true)) {
            $this->addError('widgetType', 'Choose a visualization type.');

            return;
        }

        if ($this->isDatasetWidget($this->widgetType) && $this->dataset === '') {
            $this->addError('dataset', 'Choose what this chart should show.');

            return;
        }

        if ($this->isMetricWidget($this->widgetType) && $this->metric === '') {
            $this->addError('metric', 'Choose which number to display.');

            return;
        }

        $dashboard = $this->selectedDashboard();

        if ($dashboard === null && trim($this->newDashboardName) !== '') {
            $dashboard = Dashboard::create([
                'owner_id' => $user->id,
                'name' => trim($this->newDashboardName),
                'layout' => ['version' => 1, 'widgets' => []],
            ]);
        }

        if ($dashboard === null) {
            $this->addError('dashboardId', 'Choose a dashboard to add this visualization to.');

            return;
        }

        // Connect the table if this dashboard does not read it yet.
        $source = $dashboard->sources()->where('table_key', $this->tableKey)->first();

        if ($source === null) {
            $source = $dashboard->sources()->create([
                'table_key' => $this->tableKey,
                'alias' => DashboardSource::uniqueAliasFor($dashboard, Str::slug($this->tableKey, '_')),
                'position' => (int) $dashboard->sources()->max('position') + 1,
            ]);
        }

        $definition = app(WidgetRegistry::class)->make($this->widgetType)->definition();
        $size = $definition['defaultSize'] ?? ['w' => 4, 'h' => 2];

        $layout = $dashboard->layout;
        if ($layout === null) {
            $layout = $dashboard->wasRecentlyCreated ? ['version' => 1, 'widgets' => []] : config('dashboard.default_layout');
        }
        $layout['widgets'][] = [
            'id' => $this->widgetType.'-'.Str::lower(Str::random(6)),
            'type' => $this->widgetType,
            'w' => (int) min(12, max(1, $size['w'])),
            'h' => (int) min(6, max(1, $size['h'])),
            'props' => $this->buildProps($source->alias),
        ];

        $dashboard->update([
            'layout' => app(GridLayoutNormalizer::class)->normalize($layout),
        ]);

        DashboardAudit::log($dashboard, 'widget_added', [
            'widget_type' => $this->widgetType,
            'dataset' => $this->isDatasetWidget($this->widgetType) ? $this->dataset : null,
            'metric' => $this->isMetricWidget($this->widgetType) ? $this->metric : null,
            'by_superadmin' => $user->isSuperadmin() && $dashboard->owner_id !== $user->id,
        ]);

        $this->redirectRoute('dashboards.show', $dashboard);
    }

    /**
     * A live preview of the generated widget, resolved through the real
     * engine so the user sees exactly what will be saved.
     *
     * @return array{view: string, data: array<string, mixed>}|null
     */
    private function preview(): ?array
    {
        if ($this->widgetType === '' || ($this->isDatasetWidget($this->widgetType) && $this->dataset === '')) {
            return null;
        }

        $alias = $this->resolvedAlias() ?? 'preview';
        $summary = $this->tableKey !== ''
            ? app(TableAggregationService::class)->summary($this->tableKey)
            : ['metrics' => [], 'datasets' => []];

        $context = DashboardContext::fromSummary('All regions', '12M', [])
            ->withSources([$alias => $summary]);

        try {
            return app(WidgetRegistry::class)->make($this->widgetType)->resolve($this->buildProps($alias), $context);
        } catch (\Throwable) {
            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.widget-wizard', [
            'tables' => $this->tableOptions(),
            'dashboards' => $this->editableDashboards(),
            'datasets' => $this->availableDatasets(),
            'metrics' => $this->availableMetrics(),
            'datasetWidgets' => self::DATASET_WIDGETS,
            'metricWidgets' => self::METRIC_WIDGETS,
            'widgetDefinitions' => app(WidgetRegistry::class)->definitions(),
            'preview' => $this->preview(),
        ])
            ->layout('layouts.dashboard')
            ->title('Create visualization');
    }

    /** @return array<int, array{key: string, label: string}> */
    private function tableOptions(): array
    {
        $catalog = app(TableCatalog::class);

        $core = collect(TableCatalog::CORE)
            ->map(fn (array $table, string $key): array => ['key' => $key, 'label' => $table['label']])
            ->values();

        $dynamic = DynamicTable::query()
            ->orderBy('name')
            ->get(['key', 'name'])
            ->map(fn ($table): array => ['key' => $table->key, 'label' => $table->name]);

        return $core->concat($dynamic)->all();
    }
}
