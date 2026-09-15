<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\DashboardLayout;
use App\Services\ProductDashboardService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\DashboardLayoutEngine;
use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\ExpressionSyntaxError;
use App\Support\Dashboard\GridLayoutNormalizer;
use App\Support\Dashboard\WidgetRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class Dashboard extends Component
{
    public string $region = 'All regions';

    public string $period = '12M';

    /** Set when the viewer's role pins the dashboard to their own region. */
    public bool $regionLocked = false;

    /** Edit mode for the grid-layout-engine widget grid. */
    public bool $customizing = false;

    /**
     * The working copy of the layout while customizing: drags, resizes,
     * removals, added widgets and settings changes all land here and are
     * only persisted when the user clicks Done.
     *
     * @var array{version?: int, widgets?: array<int, array<string, mixed>>}
     */
    public array $draftLayout = [];

    /** Id of the widget whose settings modal is open. */
    public ?string $settingsWidgetId = null;

    /** Props being edited in the settings modal. */
    public array $settingsProps = [];

    /** Settings form schema of the widget being edited. */
    public array $settingsSchema = [];

    /** Validation message shown inside the settings modal. */
    public ?string $settingsError = null;

    /** True right after a successful Apply — the modal stays open. */
    public bool $settingsApplied = false;

    /** How many canvas graphs the last Apply received from the editors. */
    public int $settingsGraphCount = 0;

    /** Increments on every settings open — forces the canvas component
     *  to re-initialize (new wire:key) instead of surviving the DOM morph
     *  with stale state. */
    public int $settingsOpenCount = 0;

    public function mount(): void
    {
        // Regional managers operate their own region: scope the dashboard to
        // it and lock the selector. National/president roles see everything.
        $user = auth()->user();

        if ($user?->role === UserRole::RegionalManager && filled($user->region)) {
            $this->region = $user->region;
            $this->regionLocked = true;
        }
    }

    public function updatedRegion(): void
    {
        // Region drives every widget; recompute happens in render().
    }

    public function updatedPeriod(): void
    {
        // Period drives the installation trend and its delta; recompute in render().
    }

    /**
     * Enter edit mode (snapshot the current layout into the draft) or leave
     * it via Cancel (discard the draft — nothing is persisted).
     */
    public function toggleCustomizing(): void
    {
        if ($this->customizing) {
            $this->customizing = false;
            $this->discardDraft();

            return;
        }

        $user = auth()->user();
        $saved = $user ? DashboardLayout::query()->where('user_id', $user->id)->first() : null;

        // Deep-copy so the draft never aliases the config default.
        $this->draftLayout = json_decode(
            json_encode($saved?->layout ?? config('dashboard.default_layout')),
            true,
        ) ?: ['version' => 1, 'widgets' => []];

        $this->customizing = true;
    }

    /**
     * Done: persist the draft (geometry synced from the client editor)
     * and leave edit mode. Called by the editor's Done button — drag,
     * resize and settings changes deliberately do NOT persist, so the user
     * stays in customization mode until they commit.
     */
    #[On('dashboard-layout-save')]
    public function saveLayout(array $layout = []): void
    {
        $user = auth()->user();

        if (! $user) {
            return; // session ended mid-edit; nothing to persist
        }

        $draft = $this->customizing && $this->draftLayout !== []
            ? $this->draftLayout
            : ['version' => 1, 'widgets' => []];

        // An empty payload means the client sent no geometry — keep the
        // draft's own order and sizes.
        if ($layout !== []) {
            $draft = $this->mergeGeometry($draft, $layout);
        }

        $normalized = app(GridLayoutNormalizer::class)->normalize($draft);

        DashboardLayout::updateOrCreate(
            ['user_id' => $user->id],
            ['layout' => $normalized],
        );

        $this->customizing = false;
        $this->discardDraft();
    }

    /**
     * Live geometry sync from the client editor (after a drag, resize or
     * removal). Updates the draft only — no persistence, edit mode stays on.
     */
    #[On('dashboard-layout-sync')]
    public function syncLayout(array $layout = []): void
    {
        if (! $this->customizing || $this->draftLayout === [] || $layout === []) {
            return;
        }

        $this->draftLayout = $this->mergeGeometry($this->draftLayout, $layout);
    }

    /** Back to the shipped default layout: drop the saved one and exit. */
    public function resetLayout(): void
    {
        DashboardLayout::where('user_id', auth()->id())->delete();
        $this->customizing = false;
        $this->discardDraft();
    }

    /**
     * Append a widget of the given type to the draft, with the factory's
     * default size and props. Stays in edit mode. Gated behind the
     * allow_add_widgets flag — the generator is opt-in.
     */
    public function addWidget(string $type): void
    {
        if (! config('dashboard.allow_add_widgets')) {
            return;
        }

        if (! $this->customizing || ! config("dashboard.widgets.{$type}")) {
            return;
        }

        $definition = app(WidgetRegistry::class)->make($type)->definition();
        $size = $definition['defaultSize'] ?? ['w' => 4, 'h' => 2];

        $this->draftLayout['widgets'][] = [
            'id' => $type.'-'.strtolower(Str::random(6)),
            'type' => $type,
            'w' => (int) min(12, max(1, $size['w'])),
            'h' => (int) min(6, max(1, $size['h'])),
            'props' => $definition['defaultProps'] ?? [],
        ];

        $this->dispatch('close-modal', name: 'add-widget');
    }

    /** Open the settings modal for one widget in the draft. */
    public function editWidget(string $id): void
    {
        if (! $this->customizing) {
            return;
        }

        $widget = collect($this->draftLayout['widgets'] ?? [])->firstWhere('id', $id);

        if (! $widget) {
            return;
        }

        $definition = app(WidgetRegistry::class)->make($widget['type'])->definition();

        $this->settingsWidgetId = $id;
        $this->settingsSchema = $definition['settings'] ?? [];
        $this->settingsError = null;
        $this->settingsApplied = false;
        $this->settingsGraphCount = 0;
        $this->settingsOpenCount++;

        // Seed every schema field so wire:model always has a key to bind to,
        // preferring the widget's own props, then the field default, then
        // the factory's defaultProps.
        $props = $widget['props'] ?? [];
        $defaultProps = $definition['defaultProps'] ?? [];

        foreach ($this->settingsSchema as $field) {
            $key = $field['key'] ?? '';

            if ($key !== '' && ! array_key_exists($key, $props)) {
                $props[$key] = $field['default'] ?? ($defaultProps[$key] ?? '');
            }
        }

        $this->settingsProps = $props;

        // Visual expression tree builder: seed the editable canvas. The
        // persisted graph (exact blocks, positions, connections — valid
        // mid-build or not) wins; otherwise the graph is rebuilt from the
        // stored expression string. null graph + unparseable string =
        // "advanced" fallback.
        $engine = app(ExpressionEngine::class);

        foreach ($this->settingsSchema as $index => $field) {
            if (($field['type'] ?? '') === 'expression' && ($field['visual'] ?? false)) {
                $key = $field['key'];
                $current = trim((string) ($this->settingsProps[$key] ?? ''));
                $treeKey = $key.'_tree';

                if (! array_key_exists($treeKey, $this->settingsProps) || ! is_array($this->settingsProps[$treeKey])) {
                    $this->settingsProps[$treeKey] = $current === ''
                        ? null
                        : $engine->toTree($current);
                }

                $this->settingsSchema[$index]['tree'] = $current === ''
                    ? []
                    : $engine->toTree($current);
                $this->settingsSchema[$index]['graph'] = $this->settingsProps[$treeKey];

                $this->debugLog('editWidget.seed', [
                    'widget' => $id,
                    'field' => $key,
                    'stored_graph_in_props' => array_key_exists($treeKey, $widget['props'] ?? []),
                    'seeded_graph_nodes' => is_array($this->settingsSchema[$index]['graph']) && isset($this->settingsSchema[$index]['graph']['nodes'])
                        ? count($this->settingsSchema[$index]['graph']['nodes'])
                        : null,
                ]);
            }
        }

        $this->dispatch('open-modal', name: 'widget-settings');
    }

    /**
     * Validate the settings form (required fields + expression syntax) and
     * apply it to the draft widget. The widget re-renders live; edit mode
     * stays on and nothing is persisted until Done.
     *
     * The canvas graphs arrive INSIDE this call: the Apply button collects
     * them from the editors' in-memory registry at click time, so the
     * exact blocks the user built are in this request — no separate sync
     * request can be lost, superseded, or arrive late.
     *
     * @param  array<string, mixed>  $graphs  graphKey → raw canvas graph
     */
    public function applyWidgetSettings(array $graphs = []): void
    {
        if (! $this->customizing || ! $this->settingsWidgetId) {
            return;
        }

        // Merge the graphs the client sent with this very click into the
        // settings props, where the pass-through below picks them up.
        $merged = 0;

        foreach ($graphs as $key => $payload) {
            $propKey = $this->normalizeTreeKey($key);

            if ($propKey !== null) {
                $this->settingsProps[$propKey] = $payload;
                $merged++;
            }
        }

        $this->debugLog('apply', [
            'widget' => $this->settingsWidgetId,
            'graphs_sent' => array_keys($graphs),
            'graphs_merged' => $merged,
        ]);

        $engine = app(ExpressionEngine::class);
        $errors = [];
        $clean = [];

        foreach ($this->settingsSchema as $field) {
            $key = $field['key'] ?? '';
            $type = $field['type'] ?? 'text';

            if ($key === '') {
                continue;
            }

            $value = $this->settingsProps[$key] ?? '';

            // Checkboxes: always present, never "empty" — a cleared box is a
            // meaningful false, not a missing value.
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

                continue; // empty optional fields are dropped from props
            }

            if ($type === 'expression') {
                try {
                    $engine->parse((string) $value);
                } catch (ExpressionSyntaxError $e) {
                    $errors[] = ($field['label'] ?? $key).': '.$e->getMessage();

                    continue;
                }
            } elseif ($type === 'metric') {
                $allowed = $field['options'] ?? array_keys(config('dashboard.metric_labels', []));

                if (! in_array((string) $value, $allowed, true)) {
                    $errors[] = ($field['label'] ?? $key).' is not a known metric.';

                    continue;
                }
            } elseif ($type === 'dataset') {
                $allowed = $field['options'] ?? array_keys(config('dashboard.datasets', []));

                if (! in_array((string) $value, $allowed, true)) {
                    $errors[] = ($field['label'] ?? $key).' is not a known dataset.';

                    continue;
                }
            }

            $clean[$key] = $value;
        }

        if ($errors !== []) {
            $this->settingsError = implode(' ', $errors);

            return;
        }

        // Visual fields also carry their raw canvas graph (formula_tree) —
        // the exact blocks the user built, kept so the editor reopens in
        // the same state, ready to edit, change or delete blocks.
        foreach ($this->settingsSchema as $field) {
            if (($field['type'] ?? '') === 'expression' && ($field['visual'] ?? false)) {
                $treeKey = ($field['key'] ?? '').'_tree';
                $raw = $this->settingsProps[$treeKey] ?? null;
                $graph = $this->sanitizeGraph($raw);

                $this->debugLog('apply.graph', [
                    'key' => $treeKey,
                    'raw_type' => gettype($raw),
                    'raw_nodes' => is_array($raw) ? count($raw['nodes'] ?? []) : null,
                    'stored' => $graph !== null,
                ]);

                if ($graph !== null) {
                    $clean[$treeKey] = $graph;
                }
            }
        }

        foreach ($this->draftLayout['widgets'] as $index => $widget) {
            if (($widget['id'] ?? null) === $this->settingsWidgetId) {
                $this->draftLayout['widgets'][$index]['props'] = $clean;
                break;
            }
        }

        // Non-destructive Apply: the modal STAYS OPEN with the canvas and
        // every field intact, so the user can keep editing their blocks.
        // The draft now carries the applied settings (visible behind the
        // modal and saved on Done). Closing is explicit via Close / ✕.
        $this->settingsApplied = true;
        $this->settingsError = null;
        $this->settingsGraphCount = $merged;

        $this->debugLog('apply.success', [
            'widget' => $this->settingsWidgetId,
            'clean_keys' => array_keys($clean),
        ]);
    }

    /** Close the settings modal without applying anything. */
    public function cancelWidgetSettings(): void
    {
        $this->settingsWidgetId = null;
        $this->settingsProps = [];
        $this->settingsSchema = [];
        $this->settingsError = null;
        $this->settingsApplied = false;
        $this->settingsGraphCount = 0;
    }

    /**
     * Receive the canvas graph from the expression tree editor as an
     * explicit action call (JSON string param) — the most reliable sync
     * channel, immune to property-set batching quirks. Stored into the
     * settings props the same place a typed formula would land; Apply
     * copies it into the widget's props.
     */
    public function commitTreeGraph(string $key, ?string $graphJson): void
    {
        if (! $this->customizing || ! $this->settingsWidgetId) {
            return;
        }

        $propKey = $this->normalizeTreeKey($key);

        if ($propKey === null) {
            return; // only companion graph keys are accepted
        }

        $decoded = $graphJson === null ? null : json_decode($graphJson, true);

        $this->settingsProps[$propKey] = $decoded;

        $this->debugLog('commitTreeGraph', [
            'key' => $propKey,
            'nodes' => is_array($decoded) ? count($decoded['nodes'] ?? []) : null,
        ]);
    }

    /**
     * The client addresses a canvas graph by its full wire path
     * ("settingsProps.current_tree"); normalize it to the bare props key
     * ("current_tree"), rejecting anything that is not a companion graph.
     */
    private function normalizeTreeKey(string $key): ?string
    {
        $propKey = str_starts_with($key, 'settingsProps.')
            ? substr($key, strlen('settingsProps.'))
            : $key;

        return str_ends_with($propKey, '_tree') ? $propKey : null;
    }

    private function discardDraft(): void
    {
        $this->draftLayout = [];
        $this->cancelWidgetSettings();
    }

    /**
     * Temporary diagnostic log for the formula-canvas persistence chase:
     * one dedicated file so the client → server journey can be traced
     * step by step (editor commits, Apply payloads, reopen seeding).
     */
    private function debugLog(string $event, array $context): void
    {
        Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/dashboard-debug.log'),
        ])->info($event, $context);
    }

    /**
     * Whitelist-and-cap a persisted canvas graph from the client: only
     * known node fields survive, string lengths and node counts are
     * capped — user-controlled JSON never lands in props unchecked.
     *
     * @return array{nodes: array<int, array<string, mixed>>, root: string|null}|null
     */
    private function sanitizeGraph(mixed $graph): ?array
    {
        // The client may deliver the graph as a JSON string (action-call
        // channel) or as an already-decoded array (property sync).
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

            $nodes[] = [
                'id' => $id,
                'kind' => $kind,
                'value' => $value,
                'inputs' => $inputs,
                'x' => (int) ($node['x'] ?? 0),
                'y' => (int) ($node['y'] ?? 0),
            ];
        }

        if ($nodes === []) {
            return null;
        }

        return [
            'nodes' => $nodes,
            'root' => is_string($graph['root'] ?? null) ? substr($graph['root'], 0, 32) : null,
        ];
    }

    /**
     * Reorder the draft's widgets to match the client DOM and apply the
     * edited spans. The DOM payload is authoritative: widgets absent from
     * it were removed client-side and are dropped here too; unknown ids
     * are ignored.
     *
     * @param  array{version?: int, widgets?: array<int, array<string, mixed>>}  $draft
     * @param  array<int, array<string, mixed>>  $geometry
     * @return array{version: int, widgets: array<int, array<string, mixed>>}
     */
    private function mergeGeometry(array $draft, array $geometry): array
    {
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

    public function render(ProductDashboardService $service, DashboardLayoutEngine $engine): View
    {
        $user = auth()->user();
        // Enforce the lock at render time too — a tampered property cannot
        // widen a regional manager's scope.
        $region = $this->regionLocked && $user?->role === UserRole::RegionalManager
            ? $user->region
            : $this->region;

        $summary = $service->summary($region === 'All regions' ? null : $region, $this->period);

        // Grid layout engine: while customizing we render the DRAFT (live
        // edits, nothing persisted); otherwise the user's saved layout or
        // the config default. Queried directly (not via the relation) so a
        // long-lived model instance can never serve a stale cached null.
        $context = DashboardContext::fromSummary($region, $this->period, $summary);
        $savedLayout = $user ? DashboardLayout::query()->where('user_id', $user->id)->first() : null;
        $layout = $this->customizing && $this->draftLayout !== []
            ? $this->draftLayout
            : ($savedLayout?->layout ?? config('dashboard.default_layout'));

        return view('livewire.dashboard', [
            'regionLocked' => $this->regionLocked,
            'periodOptions' => array_keys(ProductDashboardService::PERIODS),
            'grid' => $engine->build($layout, $context),
            'widgetDefinitions' => app(WidgetRegistry::class)->definitions(),
            'metricLabels' => config('dashboard.metric_labels', []),
            'metricValues' => array_filter(is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [], 'is_numeric'),
            'datasetOptions' => config('dashboard.datasets', []),
            'allowAddWidgets' => (bool) config('dashboard.allow_add_widgets'),
            ...$summary,
        ])
            ->layout('layouts.dashboard')
            ->title('Home');
    }
}
