<?php

namespace App\Livewire\Concerns;

use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\DashboardLayoutEngine;
use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\ExpressionSyntaxError;
use App\Support\Dashboard\GridLayoutNormalizer;
use App\Support\Dashboard\WidgetRegistry;
use Livewire\Attributes\On;

/**
 * Reusable layout-engine widget grid for analytics pages (TSA, TSP, …).
 *
 * Each grid key owns an independent editor state (customize, draft,
 * settings). Hosts provide the vocabulary, layout storage and gates; the
 * client editor (dashboard-grid.js) drives geometry through the shared
 * `dashboard-layout-sync` / `dashboard-layout-save` events with a grid key.
 *
 * Deliberately leaner than the Dashboard component: no data sources, no
 * add-widget flow, no audit — converted KPI cards plus full settings.
 */
trait WithCustomizableWidgets
{
    /** @var array<string, array<string, mixed>> per-grid editor state */
    public array $widgetGrids = [];

    abstract protected function canCustomizeWidgets(): bool;

    abstract protected function loadWidgetLayout(string $grid): ?array;

    abstract protected function storeWidgetLayout(string $grid, array $layout): void;

    abstract protected function clearWidgetLayout(string $grid): void;

    /** @return array<string, string> metric key => label */
    abstract protected function widgetMetricVocabulary(string $grid): array;

    /** @return array<string, string> dataset key => label */
    abstract protected function widgetDatasetVocabulary(string $grid): array;

    /** Provenance label for bare (page-owned) references. */
    abstract protected function widgetScopeLabel(string $grid): string;

    /** Provenance tone for bare references: 'source' for page-owned data. */
    protected function widgetScopeKind(string $grid): string
    {
        return 'source';
    }

    public function widgetSettingsModalName(string $grid): string
    {
        return 'widget-settings-'.$grid;
    }

    public function widgetSettingsPrefix(string $grid): string
    {
        return "widgetGrids.{$grid}.settingsProps";
    }

    /** @return array<string, mixed> */
    protected function widgetGridState(string $grid): array
    {
        return $this->widgetGrids[$grid] ?? [
            'customizing' => false,
            'draftLayout' => [],
            'settingsWidgetId' => null,
            'settingsSchema' => [],
            'settingsProps' => [],
            'settingsError' => null,
            'settingsApplied' => false,
            'settingsGraphCount' => 0,
            'settingsOpenCount' => 0,
        ];
    }

    /** @param array<string, mixed> $state */
    protected function putWidgetGridState(string $grid, array $state): void
    {
        $this->widgetGrids[$grid] = $state;
    }

    /** @param array<string, mixed> $patch */
    protected function patchWidgetGridState(string $grid, array $patch): void
    {
        $this->putWidgetGridState($grid, [...$this->widgetGridState($grid), ...$patch]);
    }

    protected function forgetWidgetGridState(string $grid): void
    {
        unset($this->widgetGrids[$grid]);
    }

    /**
     * @return array<string, array<string, string>> group => (key => label)
     */
    protected function groupedWidgetOptions(string $grid, array $flat): array
    {
        return $flat === [] ? [] : [$this->widgetScopeLabel($grid) => $flat];
    }

    public function toggleCustomizingFor(string $grid): void
    {
        abort_unless($this->canCustomizeWidgets(), 403);

        $state = $this->widgetGridState($grid);

        if ($state['customizing']) {
            $this->forgetWidgetGridState($grid);

            return;
        }

        // Deep-copy so the draft never aliases the stored layout.
        $source = $this->loadWidgetLayout($grid) ?? ['version' => 1, 'widgets' => []];

        $this->putWidgetGridState($grid, [...$state, ...[
            'customizing' => true,
            'draftLayout' => json_decode(json_encode($source), true) ?: ['version' => 1, 'widgets' => []],
        ]]);
    }

    #[On('dashboard-layout-sync')]
    public function syncWidgetGrid(array $layout = [], string $grid = 'default'): void
    {
        abort_unless($this->canCustomizeWidgets(), 403);

        $state = $this->widgetGridState($grid);

        if (! $state['customizing'] || $state['draftLayout'] === []) {
            return;
        }

        $this->patchWidgetGridState($grid, [
            'draftLayout' => $this->mergeWidgetGeometry($state['draftLayout'], $layout),
        ]);
    }

    #[On('dashboard-layout-save')]
    public function saveWidgetGrid(array $layout = [], string $grid = 'default'): void
    {
        abort_unless($this->canCustomizeWidgets(), 403);

        $state = $this->widgetGridState($grid);

        $draft = $state['customizing'] && $state['draftLayout'] !== []
            ? $state['draftLayout']
            : ['version' => 1, 'widgets' => []];

        // An empty payload means the client sent no geometry — keep the
        // draft's own order and sizes.
        if ($layout !== []) {
            $draft = $this->mergeWidgetGeometry($draft, $layout);
        }

        $this->storeWidgetLayout($grid, app(GridLayoutNormalizer::class)->normalize($draft));
        $this->forgetWidgetGridState($grid);
    }

    public function resetWidgetGrid(string $grid): void
    {
        abort_unless($this->canCustomizeWidgets(), 403);

        $this->clearWidgetLayout($grid);
        $this->forgetWidgetGridState($grid);
    }

    /** @param array<int, array<string, mixed>> $geometry */
    protected function mergeWidgetGeometry(array $draft, array $geometry): array
    {
        // An empty geometry is a real state (last widget deleted).
        if ($geometry === []) {
            return ['version' => 1, 'widgets' => []];
        }

        $byId = collect($draft['widgets'] ?? [])->keyBy('id');
        $merged = [];

        foreach ($geometry as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;

            if (! is_string($id) || ! $byId->has($id)) {
                continue;
            }

            $widget = $byId->pull($id);
            $widget['w'] = (int) min(12, max(1, (int) ($entry['w'] ?? $widget['w'])));
            $widget['h'] = (int) min(6, max(1, (int) ($entry['h'] ?? $widget['h'])));
            $merged[] = $widget;
        }

        return ['version' => 1, 'widgets' => $merged];
    }

    /** Open the settings modal for one widget in the draft. */
    public function editWidgetFor(string $grid, string $id): void
    {
        abort_unless($this->canCustomizeWidgets(), 403);

        $state = $this->widgetGridState($grid);

        if (! $state['customizing']) {
            return;
        }

        $widget = collect($state['draftLayout']['widgets'] ?? [])->firstWhere('id', $id);

        if (! $widget) {
            return;
        }

        $definition = app(WidgetRegistry::class)->make($widget['type'])->definition();
        $metricVocab = $this->widgetMetricVocabulary($grid);
        $datasetVocab = $this->widgetDatasetVocabulary($grid);

        $schema = $definition['settings'] ?? [];

        foreach ($schema as $index => $field) {
            if (($field['type'] ?? '') === 'dataset') {
                $schema[$index]['options'] = array_keys($datasetVocab);
                $schema[$index]['grouped_options'] = $this->groupedWidgetOptions($grid, $datasetVocab);
            } elseif (($field['type'] ?? '') === 'metric') {
                // Momentum fields are expression-scoped; the primary metric
                // follows the page vocabulary.
                $vocab = ($field['key'] ?? '') === 'metric' ? $metricVocab : $this->pageExpressionLabels($grid);
                $schema[$index]['options'] = array_keys($vocab);
                $schema[$index]['grouped_options'] = $this->groupedWidgetOptions($grid, $vocab);
            }
        }

        // Seed every schema field so wire:model always has a key to bind to.
        $props = $widget['props'] ?? [];
        $defaultProps = $definition['defaultProps'] ?? [];

        foreach ($schema as $field) {
            $key = $field['key'] ?? '';

            if ($key !== '' && ! array_key_exists($key, $props)) {
                $props[$key] = $field['default'] ?? ($defaultProps[$key] ?? '');
            }
        }

        $engine = app(ExpressionEngine::class);

        foreach ($schema as $index => $field) {
            if (($field['type'] ?? '') === 'expression' && ($field['visual'] ?? false)) {
                $key = $field['key'];
                $current = trim((string) ($props[$key] ?? ''));
                $treeKey = $key.'_tree';

                if (! array_key_exists($treeKey, $props) || ! is_array($props[$treeKey])) {
                    $props[$treeKey] = $current === '' ? null : $engine->toTree($current);
                }

                $schema[$index]['tree'] = $current === '' ? [] : $engine->toTree($current);
                $schema[$index]['graph'] = $props[$treeKey];
            }
        }

        $this->putWidgetGridState($grid, [...$state, ...[
            'settingsWidgetId' => $id,
            'settingsSchema' => $schema,
            'settingsProps' => $props,
            'settingsError' => null,
            'settingsApplied' => false,
            'settingsGraphCount' => 0,
            'settingsOpenCount' => $state['settingsOpenCount'] + 1,
        ]]);

        $this->dispatch('open-modal', name: $this->widgetSettingsModalName($grid));
    }

    /**
     * Bare identifiers for momentum fields, which the expression engine
     * resolves without a vocabulary. Hosts override when their scope has
     * known bare names.
     *
     * @return array<string, string>
     */
    protected function pageExpressionLabels(string $grid): array
    {
        return $this->widgetMetricVocabulary($grid);
    }

    /**
     * Validate the settings form and apply it to the draft widget.
     *
     * @param  array<string, mixed>  $graphs  graphKey → raw canvas graph
     */
    public function applyWidgetSettingsFor(string $grid, array $graphs = []): void
    {
        abort_unless($this->canCustomizeWidgets(), 403);

        $state = $this->widgetGridState($grid);

        if (! $state['customizing'] || ! $state['settingsWidgetId']) {
            return;
        }

        $props = $state['settingsProps'];

        foreach ($graphs as $key => $payload) {
            $propKey = $this->normalizeWidgetTreeKey($grid, $key);

            if ($propKey !== null) {
                $props[$propKey] = $payload;
            }
        }

        $engine = app(ExpressionEngine::class);
        $errors = [];
        $clean = [];

        foreach ($state['settingsSchema'] as $field) {
            $key = $field['key'] ?? '';
            $type = $field['type'] ?? 'text';

            if ($key === '') {
                continue;
            }

            $value = $props[$key] ?? '';

            if ($type === 'boolean') {
                $clean[$key] = (bool) $value;

                continue;
            }

            if ($type === 'number') {
                $value = $value === '' ? '' : (int) $value;
            } elseif (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' || $value === null) {
                if ($field['required'] ?? false) {
                    $errors[] = ($field['label'] ?? $key).' is required.';
                }

                continue;
            }

            if ($type === 'expression') {
                try {
                    $engine->parse((string) $value);
                } catch (ExpressionSyntaxError $e) {
                    $errors[] = ($field['label'] ?? $key).': '.$e->getMessage();

                    continue;
                }
            } elseif ($type === 'metric') {
                $allowed = $field['options'] ?? array_keys($this->widgetMetricVocabulary($grid));

                if (! in_array((string) $value, $allowed, true)) {
                    $errors[] = ($field['label'] ?? $key).' is not a known metric.';

                    continue;
                }
            } elseif ($type === 'dataset') {
                $allowed = $field['options'] ?? array_keys($this->widgetDatasetVocabulary($grid));

                if (! in_array((string) $value, $allowed, true)) {
                    $errors[] = ($field['label'] ?? $key).' is not a known dataset.';

                    continue;
                }
            }

            $clean[$key] = $value;
        }

        if ($errors !== []) {
            $this->patchWidgetGridState($grid, ['settingsProps' => $props, 'settingsError' => implode(' ', $errors)]);

            return;
        }

        foreach ($state['settingsSchema'] as $field) {
            if (($field['type'] ?? '') === 'expression' && ($field['visual'] ?? false)) {
                $treeKey = ($field['key'] ?? '').'_tree';
                $graph = $this->sanitizeWidgetGraph($props[$treeKey] ?? null);

                if ($graph !== null) {
                    $clean[$treeKey] = $graph;
                }
            }
        }

        $widgets = $state['draftLayout']['widgets'] ?? [];

        foreach ($widgets as $index => $widget) {
            if (($widget['id'] ?? null) === $state['settingsWidgetId']) {
                $widgets[$index]['props'] = $clean;

                break;
            }
        }

        $this->patchWidgetGridState($grid, [
            'settingsProps' => $props,
            'draftLayout' => [...$state['draftLayout'], 'widgets' => $widgets],
            'settingsApplied' => true,
            'settingsError' => null,
            'settingsGraphCount' => count($graphs),
        ]);
    }

    public function cancelWidgetSettingsFor(string $grid): void
    {
        $this->patchWidgetGridState($grid, [
            'settingsWidgetId' => null,
            'settingsProps' => [],
            'settingsSchema' => [],
            'settingsError' => null,
            'settingsApplied' => false,
            'settingsGraphCount' => 0,
        ]);
    }

    public function commitWidgetTreeGraph(string $grid, string $key, ?string $graphJson): void
    {
        abort_unless($this->canCustomizeWidgets(), 403);

        $state = $this->widgetGridState($grid);

        if (! $state['customizing'] || ! $state['settingsWidgetId']) {
            return;
        }

        $propKey = $this->normalizeWidgetTreeKey($grid, $key);

        if ($propKey === null) {
            return;
        }

        $props = $state['settingsProps'];
        $props[$propKey] = $graphJson === null ? null : json_decode($graphJson, true);

        $this->patchWidgetGridState($grid, ['settingsProps' => $props]);
    }

    private function normalizeWidgetTreeKey(string $grid, string $key): ?string
    {
        $prefix = $this->widgetSettingsPrefix($grid).'.';
        $propKey = str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;

        return str_ends_with($propKey, '_tree') ? $propKey : null;
    }

    /**
     * @return array{nodes: array<int, array<string, mixed>>, root: string|null}|null
     */
    private function sanitizeWidgetGraph(mixed $graph): ?array
    {
        if (is_string($graph)) {
            $graph = json_decode($graph, true);
        }

        if (! is_array($graph) || ! is_array($graph['nodes'] ?? null)) {
            return null;
        }

        $nodes = [];

        foreach (array_slice($graph['nodes'], 0, 64) as $node) {
            if (! is_array($node)) {
                continue;
            }

            $id = is_string($node['id'] ?? null) ? substr($node['id'], 0, 32) : null;
            $kind = $node['kind'] ?? null;
            $value = is_scalar($node['value'] ?? null) ? mb_substr((string) $node['value'], 0, 64) : null;

            if ($id === null || $value === null || ! in_array($kind, ['metric', 'number', 'op', 'fn'], true)) {
                continue;
            }

            $inputs = [];

            foreach (is_array($node['inputs'] ?? null) ? array_slice($node['inputs'], 0, 3) : [] as $input) {
                if (is_string($input)) {
                    $inputs[] = substr($input, 0, 32);
                }
            }

            $position = [];

            foreach (is_array($node['position'] ?? null) ? $node['position'] : [] as $axis => $coordinate) {
                if (is_numeric($coordinate)) {
                    $position[$axis] = (float) $coordinate;
                }
            }

            $nodes[] = ['id' => $id, 'kind' => $kind, 'value' => $value, 'inputs' => $inputs, 'position' => $position];
        }

        return ['nodes' => $nodes, 'root' => is_string($graph['root'] ?? null) ? substr($graph['root'], 0, 32) : null];
    }

    /**
     * Data-provenance flag for a widget card. Editorial only.
     *
     * @param  array<string, mixed>  $widget
     * @return array{label: string, kind: string}|null
     */
    protected function widgetProvenanceFor(string $grid, array $widget): ?array
    {
        $props = $widget['props'] ?? [];

        if (! is_array($props)) {
            return null;
        }

        $primary = $props['metric'] ?? $props['dataset'] ?? null;

        if (is_string($primary) && str_contains($primary, '.')) {
            return ['label' => $primary, 'kind' => 'source'];
        }

        if ((is_string($primary) && $primary !== '')
            || trim((string) ($props['formula'] ?? '')) !== ''
            || trim((string) ($props['current'] ?? '')) !== ''
            || trim((string) ($props['goal'] ?? '')) !== ''
            || (is_array($props['columns'] ?? null) && ($props['columns'] ?? []) !== [])
        ) {
            return ['label' => $this->widgetScopeLabel($grid), 'kind' => $this->widgetScopeKind($grid)];
        }

        try {
            $settings = app(WidgetRegistry::class)->make($widget['type'] ?? '')->definition()['settings'] ?? [];
        } catch (\Throwable) {
            return null;
        }

        foreach ($settings as $field) {
            if (in_array($field['type'] ?? '', ['metric', 'dataset'], true)) {
                return ['label' => 'Not configured', 'kind' => 'none'];
            }
        }

        return null;
    }

    /** Build the grid view-data for a layout + context, provenance attached. */
    protected function widgetGridView(string $grid, array $layout, DashboardContext $context, DashboardLayoutEngine $engine): array
    {
        $built = $engine->build($layout, $context);

        foreach ($built['widgets'] as $index => $widget) {
            $built['widgets'][$index]['provenance'] = $this->widgetProvenanceFor($grid, $widget);
        }

        return $built;
    }
}
