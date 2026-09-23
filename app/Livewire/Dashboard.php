<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\Dashboard as DashboardModel;
use App\Models\DashboardLayout;
use App\Models\DashboardShare;
use App\Models\DashboardSource;
use App\Models\DynamicTable;
use App\Models\HistoricalTsmsReport;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Services\ProductDashboardService;
use App\Services\TableAggregationService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\DashboardLayoutEngine;
use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\ExpressionSyntaxError;
use App\Support\Dashboard\GridLayoutNormalizer;
use App\Support\Dashboard\WidgetRegistry;
use App\Support\DashboardAudit;
use App\Support\TableCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class Dashboard extends Component
{
    #[Url]
    public string $region = 'All regions';

    #[Url]
    public string $period = '12M';

    /** Set when the viewer's role pins the dashboard to their own region. */
    public bool $regionLocked = false;

    /** Global dashboard filters — persisted via URL and applied to every source. */
    #[Url(as: 'branch')]
    public string $branchFilter = 'All branches';

    #[Url(as: 'status')]
    public string $statusFilter = 'All statuses';

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    /** The dashboard being viewed (null = Home, the personal product overview). */
    public ?int $dashboardId = null;

    public string $dashboardName = '';

    /** Effective permission from the DB, re-resolved on every render. */
    public string $dashboardPermission = 'edit';

    /** Inline header edit (title + description) on a persisted dashboard. */
    public bool $editingHeader = false;

    public string $editingName = '';

    public ?string $editingDescription = null;

    /** Share modal state. */
    public bool $showShareModal = false;

    public string $shareUserId = '';

    public string $sharePermission = 'view';

    /** Data-sources modal state. */
    public bool $showSourcesModal = false;

    public string $sourceTableKey = '';

    public string $sourceAlias = '';

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

    public function mount(?DashboardModel $dashboard = null): void
    {
        $user = auth()->user();

        // A pulled-but-unmigrated deploy should say what to run, not 500.
        if (! Schema::hasTable('dashboards')) {
            abort(503, 'Dashboard tables are missing on this server — run `php artisan migrate --force`.');
        }

        if ($dashboard !== null) {
            abort_unless($dashboard->canBeViewedBy($user), 403);

            // System dashboards are templates: opening one lands the user on
            // their editable personal copy (created once). Superadmins curate
            // the template directly (audited); others may only open a system
            // board if a superadmin has shared it, then they get a copy.
            if ($dashboard->is_system && $user !== null && ! $user->isSuperadmin()) {
                abort_unless($dashboard->shares()->where('user_id', $user->id)->exists(), 403);

                $this->redirectRoute('dashboards.show', $this->personalCopyOf($dashboard, $user), navigate: true);

                return;
            }

            $this->dashboardId = $dashboard->id;
            $this->dashboardName = $dashboard->name;
            $this->dashboardPermission = $dashboard->permissionFor($user) ?? 'view';
        }

        // Regional managers operate their own region: scope the dashboard to
        // it and lock the selector. National/president roles see everything.
        if ($user?->role === UserRole::RegionalManager && filled($user->region)) {
            $this->region = $user->region;
            $this->regionLocked = true;
        }
    }

    /**
     * The dashboard model for the current view (null = Home). Queried fresh
     * so permission checks can never trust a tampered snapshot property.
     */
    private function dashboard(): ?DashboardModel
    {
        return $this->dashboardId !== null
            ? DashboardModel::query()->find($this->dashboardId)
            : null;
    }

    /**
     * The user's editable copy of a system dashboard template: found by name
     * or created once (layout + sources copied). Idempotent, so re-opening
     * the template always returns the same copy.
     */
    private function personalCopyOf(DashboardModel $system, User $user): DashboardModel
    {
        // Include trashed copies: re-opening a template after archiving your
        // copy restores it instead of creating a duplicate with the same name.
        $copy = DashboardModel::withTrashed()
            ->where('owner_id', $user->id)
            ->where('name', $system->name)
            ->first();

        if ($copy !== null) {
            if ($copy->trashed()) {
                $copy->restore();
            }

            return $copy;
        }

        $copy = DashboardModel::create([
            'owner_id' => $user->id,
            'name' => $system->name,
            'description' => $system->description,
            'layout' => $system->layout,
        ]);

        foreach ($system->sources()->get() as $source) {
            $copy->sources()->create([
                'table_key' => $source->table_key,
                'alias' => $source->alias,
                'position' => $source->position,
                'settings' => $source->settings,
            ]);
        }

        return $copy;
    }

    private function canEditDashboard(): bool
    {
        $dashboard = $this->dashboard();

        return $dashboard === null || $dashboard->canBeEditedBy(auth()->user());
    }

    public function startHeaderEdit(): void
    {
        $this->guardEdit();

        $dashboard = $this->dashboard();

        if ($dashboard === null) {
            return;
        }

        $this->editingHeader = true;
        $this->editingName = $dashboard->name;
        $this->editingDescription = $dashboard->description;
    }

    public function cancelHeaderEdit(): void
    {
        $this->editingHeader = false;
        $this->reset(['editingName', 'editingDescription']);
    }

    public function saveHeader(): void
    {
        $this->guardEdit();

        $dashboard = $this->dashboard();

        if ($dashboard === null) {
            return;
        }

        $this->validate([
            'editingName' => ['required', 'string', 'max:100'],
            'editingDescription' => ['nullable', 'string', 'max:500'],
        ]);

        $name = trim($this->editingName);
        $description = trim((string) $this->editingDescription);
        $description = $description !== '' ? $description : null;

        $dashboard->update(['name' => $name, 'description' => $description]);

        DashboardAudit::log($dashboard, 'renamed', ['name' => $name, 'description' => $description]);
        $this->dispatch('dashboard-list-updated');

        $this->dashboardName = $name;
        $this->editingHeader = false;
        $this->reset(['editingName', 'editingDescription']);
    }

    private function guardEdit(): void
    {
        abort_unless($this->canEditDashboard(), 403);
    }

    /**
     * The region scope every data source aggregates under (null = all).
     */
    private function regionScope(): ?string
    {
        $user = auth()->user();

        $region = $this->regionLocked && $user?->role === UserRole::RegionalManager
            ? $user->region
            : $this->region;

        return $region === 'All regions' ? null : $region;
    }

    /**
     * Global filter bag applied to every source. Empty strings and "All …"
     * sentinels are normalized to null so services can simply `when($filters['branch'], ...)`.
     *
     * @return array{branch: ?string, status: ?string, date_from: ?string, date_to: ?string, region: ?string, period: string}
     */
    private function filterScope(): array
    {
        return [
            'branch' => $this->branchFilter !== 'All branches' && trim($this->branchFilter) !== '' ? trim($this->branchFilter) : null,
            'status' => $this->statusFilter !== 'All statuses' && trim($this->statusFilter) !== '' ? trim($this->statusFilter) : null,
            'date_from' => trim($this->dateFrom) !== '' ? trim($this->dateFrom) : null,
            'date_to' => trim($this->dateTo) !== '' ? trim($this->dateTo) : null,
            'region' => $this->regionScope(),
            'period' => $this->period,
        ];
    }

    public function clearFilters(): void
    {
        $this->branchFilter = 'All branches';
        $this->statusFilter = 'All statuses';
        $this->dateFrom = '';
        $this->dateTo = '';
    }

    /** @return array<int, string> */
    private function branchOptions(): array
    {
        $branches = collect()
            // Installations carry no branch of their own — it lives on the account.
            ->merge(Installation::query()
                ->join('accounts', 'accounts.id', '=', 'installations.account_id')
                ->distinct()
                ->whereNotNull('accounts.branch')
                ->where('accounts.branch', '<>', '')
                ->pluck('accounts.branch'))
            ->merge(ServiceRequest::query()->distinct()->whereNotNull('branch')->where('branch', '<>', '')->pluck('branch'))
            ->merge(HistoricalTsmsReport::query()->distinct()->whereNotNull('branch')->where('branch', '<>', '')->pluck('branch'))
            ->merge(TechnicalPersonnel::query()->distinct()->whereNotNull('branch')->where('branch', '<>', '')->pluck('branch'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return array_merge(['All branches'], $branches);
    }

    /** @return array<int, string> */
    private function statusOptions(): array
    {
        $statuses = collect()
            ->merge(Installation::query()->distinct()->whereNotNull('device_status')->where('device_status', '<>', '')->pluck('device_status'))
            ->merge(ServiceRequest::query()->distinct()->whereNotNull('group_status')->where('group_status', '<>', '')->pluck('group_status'))
            ->merge(TechnicalReport::query()->distinct()->whereNotNull('service_status')->where('service_status', '<>', '')->pluck('service_status'))
            ->merge(HistoricalTsmsReport::query()->distinct()->whereNotNull('status')->where('status', '<>', '')->pluck('status'))
            ->unique()
            ->map(fn ($s) => trim((string) $s))
            ->filter()
            ->sort()
            ->values()
            ->all();

        return array_merge(['All statuses'], $statuses);
    }

    /**
     * The dataset vocabulary for widget settings. Boards with connected
     * sources offer ONLY those sources ("alias.dataset") — the shipped
     * PDB defaults appear solely on sourceless boards, so a widget can
     * never silently read the Product Database behind a connected table.
     *
     * @return array<string, string> key => label
     */
    private function datasetOptions(): array
    {
        $dashboard = $this->dashboard();

        if ($dashboard === null || ! $dashboard->sources()->exists()) {
            return config('dashboard.datasets', []);
        }

        $options = [];
        $catalog = app(TableCatalog::class);
        $aggregation = app(TableAggregationService::class);

        foreach ($dashboard->sources()->get() as $source) {
            $label = $catalog->resolve($source->table_key)['label'] ?? $source->table_key;
            $summary = $aggregation->summary($source->table_key, $this->regionScope());

            foreach (array_keys($summary['datasets']) as $name) {
                $options[$source->alias.'.'.$name] = $label.' · '.ucfirst(str_replace('_', ' ', $name));
            }
        }

        return $options;
    }

    /**
     * The metric vocabulary for widget settings. Boards with connected
     * sources offer ONLY those sources ("alias.metric") — the shipped
     * PDB defaults appear solely on sourceless boards, so a widget can
     * never silently read the Product Database behind a connected table.
     *
     * @return array<string, string> key => label
     */
    private function metricOptions(): array
    {
        $dashboard = $this->dashboard();

        if ($dashboard === null || ! $dashboard->sources()->exists()) {
            return config('dashboard.metric_labels', []);
        }

        $options = [];
        $catalog = app(TableCatalog::class);
        $aggregation = app(TableAggregationService::class);

        foreach ($dashboard->sources()->get() as $source) {
            $label = $catalog->resolve($source->table_key)['label'] ?? $source->table_key;
            $summary = $aggregation->summary($source->table_key, $this->regionScope());
            $metrics = is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];

            foreach (array_keys($metrics) as $name) {
                $options[$source->alias.'.'.$name] = $label.' · '.ucfirst(str_replace('_', ' ', $name));
            }
        }

        return $options;
    }

    /**
     * Group a flat key=>label vocabulary for <optgroup> display: shipped
     * PDB entries under "Product Database", connected-source entries under
     * their table label.
     *
     * @param  array<string, string>  $flat
     * @return array<string, array<string, string>> group => (key => label)
     */
    private function groupedOptions(array $flat): array
    {
        $sourceLabels = [];

        foreach ($this->dashboard()?->sources()->get() ?? [] as $source) {
            $sourceLabels[$source->alias] = app(TableCatalog::class)->resolve($source->table_key)['label'] ?? $source->table_key;
        }

        $grouped = [];

        foreach ($flat as $key => $label) {
            if (str_contains($key, '.')) {
                [$alias] = explode('.', $key, 2);
                $group = $sourceLabels[$alias] ?? $alias;
            } else {
                $group = 'Product Database';
            }

            $grouped[$group][$key] = $label;
        }

        return $grouped;
    }

    /**
     * Data-provenance flag for a widget card: which table its contents come
     * from. Editorial only — it never connects anything.
     *
     * @param  array<string, mixed>  $widget
     * @return array{label: string, kind: string}|null
     */
    private function widgetProvenance(array $widget): ?array
    {
        $props = $widget['props'] ?? [];

        if (! is_array($props)) {
            return null;
        }

        $primary = $props['metric'] ?? $props['dataset'] ?? null;

        if (is_string($primary) && str_contains($primary, '.')) {
            [$alias] = explode('.', $primary, 2);

            return ['label' => $this->sourceLabel($alias) ?? $alias, 'kind' => 'source'];
        }

        if ((is_string($primary) && $primary !== '')
            || trim((string) ($props['formula'] ?? '')) !== ''
            || trim((string) ($props['current'] ?? '')) !== ''
            || trim((string) ($props['goal'] ?? '')) !== ''
            || (is_array($props['columns'] ?? null) && ($props['columns'] ?? []) !== [])
        ) {
            // Bare keys and hand-written expressions resolve against the
            // PDB-scoped vocabulary.
            return ['label' => 'Product Database', 'kind' => 'pdb'];
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

    private function sourceLabel(string $alias): ?string
    {
        $source = $this->dashboard()?->sources()->where('alias', $alias)->first();

        if ($source === null) {
            return null;
        }

        return app(TableCatalog::class)->resolve($source->table_key)['label'] ?? $source->table_key;
    }

    /**
     * First connected-source metric key ("alias.metric"), or null when this
     * dashboard has no sources. New widgets default here instead of the
     * shipped PDB metric so source-connected boards stop opening on the
     * Product Database.
     */
    private function firstSourceMetricKey(): ?string
    {
        if ($this->dashboard() === null) {
            return null;
        }

        foreach (array_keys($this->metricOptions()) as $key) {
            if (str_contains($key, '.')) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The connected sources resolved to aggregation summaries, keyed by alias.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, array{metrics: array<string, mixed>, datasets: array<string, array<int, array<string, mixed>>>}>
     */
    private function sourceData(array $filters = []): array
    {
        $dashboard = $this->dashboard();

        if ($dashboard === null) {
            return [];
        }

        $aggregation = app(TableAggregationService::class);
        $data = [];
        $region = $this->regionScope();
        $filtersWithRegion = array_merge($filters, ['region' => $region]);

        foreach ($dashboard->sources()->get() as $source) {
            $data[$source->alias] = $aggregation->summary($source->table_key, $region, $filtersWithRegion);
        }

        return $data;
    }

    /**
     * Add or update a person's access to this dashboard (owner/edit only).
     */
    public function shareDashboard(): void
    {
        $this->guardEdit();

        $dashboard = $this->dashboard();

        if ($dashboard === null) {
            return;
        }

        $this->validate([
            'shareUserId' => ['required', 'integer', 'exists:users,id'],
            'sharePermission' => ['required', 'in:view,edit'],
        ]);

        $userId = (int) $this->shareUserId;

        if ($userId === $dashboard->owner_id) {
            $this->addError('shareUserId', 'The owner already has full access.');

            return;
        }

        DashboardShare::updateOrCreate(
            ['dashboard_id' => $dashboard->id, 'user_id' => $userId],
            ['permission' => $this->sharePermission, 'shared_by' => auth()->id()],
        );

        DashboardAudit::log($dashboard, 'shared', [
            'user_id' => $userId,
            'permission' => $this->sharePermission,
            'by_superadmin' => auth()->user()?->isSuperadmin() && $dashboard->owner_id !== auth()->id(),
        ]);

        $this->reset(['shareUserId']);
        $this->sharePermission = 'view';
    }

    public function unshareDashboard(int $shareId): void
    {
        $this->guardEdit();

        $dashboard = $this->dashboard();

        if ($dashboard === null) {
            return;
        }

        $share = DashboardShare::query()
            ->where('dashboard_id', $dashboard->id)
            ->whereKey($shareId)
            ->first();

        if ($share === null) {
            return;
        }

        $share->delete();

        DashboardAudit::log($dashboard, 'unshared', ['user_id' => $share->user_id]);
    }

    /**
     * Connect a table to this dashboard. The alias is generated from the
     * table key and made unique per dashboard (widgets reference it).
     */
    public function connectSource(): void
    {
        $this->guardEdit();

        $dashboard = $this->dashboard();

        if ($dashboard === null) {
            return;
        }

        $this->validate([
            'sourceTableKey' => ['required', 'string', 'max:64'],
            'sourceAlias' => ['nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'],
        ]);

        if (! app(TableCatalog::class)->exists($this->sourceTableKey)) {
            $this->addError('sourceTableKey', 'That table does not exist.');

            return;
        }

        $alias = trim($this->sourceAlias) !== ''
            ? Str::lower(trim($this->sourceAlias))
            : Str::slug($this->sourceTableKey, '_');

        $alias = DashboardSource::uniqueAliasFor($dashboard, $alias);

        DashboardSource::create([
            'dashboard_id' => $dashboard->id,
            'table_key' => $this->sourceTableKey,
            'alias' => $alias,
            'position' => (int) $dashboard->sources()->max('position') + 1,
        ]);

        DashboardAudit::log($dashboard, 'source_connected', [
            'table_key' => $this->sourceTableKey,
            'alias' => $alias,
        ]);

        $this->reset(['sourceTableKey', 'sourceAlias']);
    }

    public function removeSource(int $sourceId): void
    {
        $this->guardEdit();

        $dashboard = $this->dashboard();

        if ($dashboard === null) {
            return;
        }

        $source = DashboardSource::query()
            ->where('dashboard_id', $dashboard->id)
            ->whereKey($sourceId)
            ->first();

        if ($source === null) {
            return;
        }

        $source->delete();

        DashboardAudit::log($dashboard, 'source_disconnected', [
            'table_key' => $source->table_key,
            'alias' => $source->alias,
        ]);
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
        $this->guardEdit();

        if ($this->customizing) {
            $this->customizing = false;
            $this->discardDraft();

            return;
        }

        $user = auth()->user();
        $dashboard = $this->dashboard();

        // Deep-copy so the draft never aliases the config default.
        $source = $dashboard?->layout
            ?? ($user ? DashboardLayout::query()->where('user_id', $user->id)->first()?->layout : null)
            ?? config('dashboard.default_layout');

        $this->draftLayout = json_decode(json_encode($source), true)
            ?: ['version' => 1, 'widgets' => []];

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
        $this->guardEdit();

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

        $dashboard = $this->dashboard();

        if ($dashboard !== null) {
            $dashboard->update(['layout' => $normalized]);

            DashboardAudit::log($dashboard, 'layout_saved', [
                'widgets' => count($normalized['widgets'] ?? []),
                'by_superadmin' => $user->isSuperadmin() && $dashboard->owner_id !== $user->id,
            ]);
        } else {
            DashboardLayout::updateOrCreate(
                ['user_id' => $user->id],
                ['layout' => $normalized],
            );
        }

        $this->customizing = false;
        $this->discardDraft();
    }

    /**
     * Live geometry sync from the client editor (after a drag, resize or
     * removal). Updates the draft only — no persistence, edit mode stays on.
     * An empty layout is a real state (last widget deleted), not a missing
     * payload: only this editor dispatches this event, and nothing persists
     * until Done (Cancel still discards).
     */
    #[On('dashboard-layout-sync')]
    public function syncLayout(array $layout = []): void
    {
        $this->guardEdit();

        if (! $this->customizing || $this->draftLayout === []) {
            return;
        }

        $this->draftLayout = $this->mergeGeometry($this->draftLayout, $layout);
    }

    /** Back to the shipped default layout: drop the saved one and exit. */
    public function resetLayout(): void
    {
        $this->guardEdit();

        $dashboard = $this->dashboard();

        if ($dashboard !== null) {
            $dashboard->update(['layout' => null]);

            DashboardAudit::log($dashboard, 'layout_reset');
        } else {
            DashboardLayout::where('user_id', auth()->id())->delete();
        }

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
        $this->guardEdit();

        if (! config('dashboard.allow_add_widgets')) {
            return;
        }

        if (! $this->customizing || ! config("dashboard.widgets.{$type}")) {
            return;
        }

        $definition = app(WidgetRegistry::class)->make($type)->definition();
        $size = $definition['defaultSize'] ?? ['w' => 4, 'h' => 2];

        $props = $definition['defaultProps'] ?? [];

        // New widgets on a source-connected dashboard measure the connected
        // table, not the Product Database: seed the primary metric to the
        // first connected source's metric. Momentum fields (trend_metric,
        // delta_metric) are evaluated by the expression engine, whose
        // identifiers are PDB-scoped, so they keep the factory default.
        // Sourceless boards keep the PDB factory defaults.
        if (($firstSourceMetric = $this->firstSourceMetricKey()) !== null) {
            foreach ($definition['settings'] ?? [] as $field) {
                $fieldKey = $field['key'] ?? '';

                if (($field['type'] ?? '') === 'metric'
                    && $fieldKey === 'metric'
                    && is_string($props[$fieldKey] ?? null)
                    && ! str_contains($props[$fieldKey], '.')
                ) {
                    $props[$fieldKey] = $firstSourceMetric;
                }
            }
        }

        $this->draftLayout['widgets'][] = [
            'id' => $type.'-'.strtolower(Str::random(6)),
            'type' => $type,
            'w' => (int) min(12, max(1, $size['w'])),
            'h' => (int) min(6, max(1, $size['h'])),
            'props' => $props,
        ];

        $this->dispatch('close-modal', name: 'add-widget');
    }

    /** Open the settings modal for one widget in the draft. */
    public function editWidget(string $id): void
    {
        $this->guardEdit();

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

        // Dataset + metric fields offer the dashboard's full vocabulary:
        // shipped defaults plus every connected source's datasets/metrics
        // (alias.name). Metric fields were previously stuck on the static
        // PDB list, so widgets on source-connected dashboards always
        // defaulted to the Product Database.
        $datasetOptions = $this->datasetOptions();
        $metricOptions = $this->metricOptions();

        foreach ($this->settingsSchema as $index => $field) {
            if (($field['type'] ?? '') === 'dataset') {
                $this->settingsSchema[$index]['options'] = array_keys($datasetOptions);
                $this->settingsSchema[$index]['grouped_options'] = $this->groupedOptions($datasetOptions);
            } elseif (($field['type'] ?? '') === 'metric') {
                // Momentum fields (trend_metric, delta_metric) are
                // expression-scoped: the engine only understands bare PDB
                // identifiers, so they keep the shipped list. The primary
                // metric follows the dashboard's live vocabulary.
                $vocab = ($field['key'] ?? '') === 'metric'
                    ? $metricOptions
                    : config('dashboard.metric_labels', []);
                $this->settingsSchema[$index]['options'] = array_keys($vocab);
                $this->settingsSchema[$index]['grouped_options'] = $this->groupedOptions($vocab);
            }
        }

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

        // A widget opened on a source-connected dashboard should not keep a
        // PDB default it can no longer explain: when the seeded primary
        // metric is outside the live vocabulary, fall back to the first
        // connected source's metric. Momentum fields stay expression-scoped
        // (PDB identifiers); saved values already in the vocabulary are
        // left untouched.
        $sourceMetricKeys = array_values(array_filter(
            array_keys($metricOptions),
            fn (string $key): bool => str_contains($key, '.'),
        ));

        if ($sourceMetricKeys !== []) {
            foreach ($this->settingsSchema as $field) {
                $key = $field['key'] ?? '';

                if (($field['type'] ?? '') === 'metric'
                    && $key === 'metric'
                    && ! array_key_exists((string) ($props[$key] ?? ''), $metricOptions)
                ) {
                    $props[$key] = $sourceMetricKeys[0];
                }
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
        $this->guardEdit();

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
                // Validate against the dashboard's live vocabulary (shipped
                // metrics + connected sources), not the static PDB list.
                $allowed = $field['options'] ?? array_keys($this->metricOptions());

                if (! in_array((string) $value, $allowed, true)) {
                    $errors[] = ($field['label'] ?? $key).' is not a known metric.';

                    continue;
                }
            } elseif ($type === 'dataset') {
                // Validate against the dashboard's live vocabulary (default
                // datasets + connected sources), not the factory's static list.
                $allowed = array_keys($this->datasetOptions());

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
        $this->guardEdit();

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

        $filters = $this->filterScope();

        // Home for non-superadmins is empty onboarding (no PDB default, no redirect).
        // Superadmin keeps the Product Database overview as Home.
        $isEmptyHome = $this->dashboardId === null && $user !== null && ! $user->isSuperadmin();

        if ($isEmptyHome) {
            $summary = [
                'metrics' => [],
                'regions' => [],
                'regionMax' => ['products' => 1, 'warranty' => 1],
                'installTrend' => [],
                'installArea' => ['area' => '', 'line' => '', 'dots' => []],
                'machineTypes' => [],
                'typeMax' => 1,
                'topAccounts' => [],
                'fleetDonut' => [],
                'fleetTotal' => 1,
                'brandDonut' => [],
                'brandTotal' => 1,
                'kpis' => [],
                'sla' => [],
                'attentionSignals' => [],
                'installDelta' => null,
                'trendMonths' => 12,
                'filters' => $filters,
                'regionOptions' => ['All regions', ...ProductDashboardService::REGIONS],
                'freshness' => 'no data yet',
                'selectedRegion' => $region,
                'scopeNote' => $region,
            ];
            $context = DashboardContext::fromSummary($region, $this->period, $summary)
                ->withSources([])
                ->withFilters($filters);
            $layout = ['version' => 1, 'widgets' => []];
            $grid = $engine->build($layout, $context);

            $hasActiveFilters = $this->branchFilter !== 'All branches'
                || $this->statusFilter !== 'All statuses'
                || trim($this->dateFrom) !== ''
                || trim($this->dateTo) !== '';

            // Flag every widget with its data provenance (connected source,
            // Product Database, or not configured) for the edit-mode chips.
            // Editorial only — nothing is connected here.
            foreach ($grid['widgets'] as $index => $widget) {
                $grid['widgets'][$index]['provenance'] = $this->widgetProvenance($widget);
            }

            return view('livewire.dashboard', [
                'regionLocked' => $this->regionLocked,
                'periodOptions' => array_keys(ProductDashboardService::PERIODS),
                'branchOptions' => $this->branchOptions(),
                'statusOptions' => $this->statusOptions(),
                'hasActiveFilters' => $hasActiveFilters,
                'activeFilters' => array_filter([
                    'branch' => $filters['branch'] ?? null,
                    'status' => $filters['status'] ?? null,
                    'from' => $filters['date_from'] ?? null,
                    'to' => $filters['date_to'] ?? null,
                ]),
                'grid' => $grid,
                'widgetDefinitions' => app(WidgetRegistry::class)->definitions(),
                'metricLabels' => config('dashboard.metric_labels', []),
                'metricValues' => [],
                'datasetOptions' => $this->datasetOptions(),
                'metricOptions' => $this->metricOptions(),
                'allowAddWidgets' => (bool) config('dashboard.allow_add_widgets'),
                'dashboard' => null,
                'canEditDashboard' => false,
                'shares' => collect(),
                'sources' => collect(),
                'userOptions' => collect(),
                'tableOptions' => $this->tableOptions(),
                'isEmptyHome' => true,
                ...$summary,
            ])
                ->layout('layouts.dashboard')
                ->title('Dashboards');
        }

        $summary = $service->summary($region === 'All regions' ? null : $region, $this->period, $filters);

        // Re-resolve the dashboard + permission from the DB (never trust the
        // snapshot): a revoked share or deleted dashboard must fail closed.
        $dashboard = $this->dashboard();

        if ($this->dashboardId !== null) {
            abort_unless($dashboard?->canBeViewedBy($user) ?? false, 403);

            $this->dashboardPermission = $dashboard->permissionFor($user) ?? 'view';
        }

        // Grid layout engine: while customizing we render the DRAFT (live
        // edits, nothing persisted); otherwise the dashboard's saved layout,
        // the user's personal Home layout, or the config default.
        // Connected tables are resolved into the context so dataset-driven
        // widgets can read any source via "alias.dataset" keys.
        $context = DashboardContext::fromSummary($region, $this->period, $summary)
            ->withSources($this->sourceData($filters))
            ->withFilters($filters);
        $savedLayout = $dashboard?->layout
            ?? ($user ? DashboardLayout::query()->where('user_id', $user->id)->first()?->layout : null);

        // Owned dashboards created via Branch A start empty (no PDB default).
        // A null layout on an owned board means empty, not Home's default.
        if ($dashboard !== null && $dashboard->layout === null) {
            $savedLayout = ['version' => 1, 'widgets' => []];
        }

        $layout = $this->customizing && $this->draftLayout !== []
            ? $this->draftLayout
            : ($savedLayout ?? config('dashboard.default_layout'));

        $hasActiveFilters = $this->branchFilter !== 'All branches'
            || $this->statusFilter !== 'All statuses'
            || trim($this->dateFrom) !== ''
            || trim($this->dateTo) !== '';

        // Flag every widget with its data provenance (connected source,
        // Product Database, or not configured) for the edit-mode chips.
        // Editorial only — nothing is connected here.
        $grid = $engine->build($layout, $context);

        foreach ($grid['widgets'] as $index => $widget) {
            $grid['widgets'][$index]['provenance'] = $this->widgetProvenance($widget);
        }

        return view('livewire.dashboard', [
            'regionLocked' => $this->regionLocked,
            'periodOptions' => array_keys(ProductDashboardService::PERIODS),
            'branchOptions' => $this->branchOptions(),
            'statusOptions' => $this->statusOptions(),
            'hasActiveFilters' => $hasActiveFilters,
            'activeFilters' => array_filter([
                'branch' => $filters['branch'] ?? null,
                'status' => $filters['status'] ?? null,
                'from' => $filters['date_from'] ?? null,
                'to' => $filters['date_to'] ?? null,
            ]),
            'grid' => $grid,
            'widgetDefinitions' => app(WidgetRegistry::class)->definitions(),
            'metricLabels' => config('dashboard.metric_labels', []),
            'metricValues' => array_filter(is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [], 'is_numeric'),
            'datasetOptions' => $this->datasetOptions(),
            'metricOptions' => $this->metricOptions(),
            'allowAddWidgets' => (bool) config('dashboard.allow_add_widgets'),
            'dashboard' => $dashboard,
            'canEditDashboard' => $this->canEditDashboard(),
            'shares' => $dashboard?->shares()->with('user')->orderBy('id')->get() ?? collect(),
            'sources' => $dashboard?->sources()->get() ?? collect(),
            'userOptions' => $dashboard !== null
                ? User::query()->whereKeyNot($user?->id)->orderBy('name')->get(['id', 'name', 'email'])
                : collect(),
            'tableOptions' => $this->tableOptions(),
            ...$summary,
        ])
            ->layout('layouts.dashboard')
            ->title($dashboard?->name ?? 'Home');
    }

    /**
     * Selectable tables for the data-sources picker: core + dynamic.
     *
     * @return array<int, array{key: string, label: string}>
     */
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
