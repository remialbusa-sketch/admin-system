<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\CustomTableColumn;
use App\Models\DynamicTable;
use App\Models\HistoricalTsmsReport;
use App\Models\ImportBatch;
use App\Models\Installation;
use App\Models\RecordEditLog;
use App\Models\ServiceRequest;
use App\Models\TablePin;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use App\Services\ColumnTypeRegistry;
use App\Support\TableCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class TablesList extends Component
{
    public string $newTableName = '';

    public string $newTableDescription = '';

    /** @var array<int, array{name: string, type: string}> */
    public array $draftColumns = [];

    /** Table keys the current user has pinned to their sidebar. */
    public array $pinnedKeys = [];

    public function mount(): void
    {
        // Start the create-table modal with one blank column row.
        if ($this->draftColumns === []) {
            $this->draftColumns = [['name' => '', 'type' => 'text']];
        }

        if (auth()->check()) {
            $this->pinnedKeys = TablePin::keysFor(auth()->id());
        }
    }

    public function addDraftColumn(): void
    {
        $this->draftColumns[] = ['name' => '', 'type' => 'text'];
    }

    public function removeDraftColumn(int $index): void
    {
        unset($this->draftColumns[$index]);
        $this->draftColumns = array_values($this->draftColumns);
    }

    /**
     * Create a new user table: a dynamic_tables registry row plus a
     * table_custom_columns row per column the user defined while building it.
     */
    public function createTable(): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);

        $this->validate(['newTableName' => ['required', 'string', 'max:100']]);

        $name = trim($this->newTableName);
        $columns = collect($this->draftColumns)
            ->map(fn (array $c): array => ['name' => trim((string) ($c['name'] ?? '')), 'type' => (string) ($c['type'] ?? 'text')])
            ->filter(fn (array $c): bool => $c['name'] !== '')
            ->values();

        $calculatedNames = $columns->pluck('name')->map(fn (string $n): string => Str::lower($n))->all();

        if (count($calculatedNames) !== count(array_unique($calculatedNames))) {
            $this->addError('draftColumns', 'Column names must be unique within a table.');

            return;
        }

        $validTypes = array_keys(app(ColumnTypeRegistry::class)->all());
        $columns->each(function (array $c) use ($validTypes): void {
            abort_unless(in_array($c['type'], $validTypes, true), 422);
        });

        $key = $this->uniqueKey(Str::slug($name, '-'));

        $table = DynamicTable::create([
            'key' => $key,
            'name' => $name,
            'description' => trim($this->newTableDescription) ?: null,
            'created_by' => auth()->id(),
        ]);

        $position = 0;
        foreach ($columns as $column) {
            CustomTableColumn::create([
                'table_key' => $key,
                'name' => $column['name'],
                'type' => $column['type'],
                'settings' => $this->defaultColumnSettings($column['type']),
                'position' => $position++,
                'created_by' => auth()->id(),
            ]);
        }

        $this->reset(['newTableName', 'newTableDescription']);
        $this->draftColumns = [['name' => '', 'type' => 'text']];

        $this->dispatch('close-modal', name: 'create-table');
        $this->redirectRoute('tables.show', ['table' => $key]);
    }

    private function uniqueKey(string $base): string
    {
        $key = $base !== '' ? $base : 'table';
        $candidate = $key;
        $i = 2;

        while (! DynamicTable::isKeyAvailable($candidate)) {
            $candidate = $key.'-'.$i++;
        }

        return $candidate;
    }

    private function defaultColumnSettings(string $type): array
    {
        return match ($type) {
            'status', 'dropdown' => [
                'multi' => $type === 'dropdown',
                'options' => [
                    ['index' => 0, 'label' => 'New', 'color' => '#64748B'],
                    ['index' => 1, 'label' => 'In Progress', 'color' => '#2563EB'],
                    ['index' => 2, 'label' => 'Done', 'color' => '#16A34A'],
                ],
            ],
            'number' => ['precision' => 2],
            'formula' => ['expression' => ''],
            default => [],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Pinning (personal sidebar quick links)
    |--------------------------------------------------------------------------
    */

    /**
     * Pin/unpin a table for the current user. Pinning is personal (not gated
     * to Superadmin — any signed-in user curates their own sidebar), and only
     * accepts keys that actually exist (core or dynamic).
     */
    public function togglePin(string $tableKey): void
    {
        if (! auth()->check()) {
            return;
        }

        if (! app(TableCatalog::class)->exists($tableKey)) {
            return;
        }

        $userId = auth()->id();

        // If the table_pins migration hasn't run yet, pinning degrades to a
        // no-op with a notice instead of throwing (migrations may lag a deploy).
        if (! TablePin::available()) {
            session()->flash('pinsMessage', 'Pinning is unavailable until the table_pins migration has run.');

            return;
        }

        $existing = TablePin::query()->where('user_id', $userId)->where('table_key', $tableKey)->first();

        if ($existing) {
            $existing->delete();
            $this->reindexPins($userId);
        } else {
            $max = (int) TablePin::query()->where('user_id', $userId)->max('position');
            TablePin::create(['user_id' => $userId, 'table_key' => $tableKey, 'position' => $max + 1]);
        }

        $this->pinnedKeys = TablePin::keysFor($userId);

        // Real-time sidebar update: the Sidebar Livewire component listens for
        // this and re-renders, so the pinned table appears/disappears on the
        // side navigation with no refresh or navigation.
        $this->dispatch('table-pins-updated');
    }

    private function reindexPins(int $userId): void
    {
        foreach (TablePin::keysFor($userId) as $index => $key) {
            TablePin::query()->where('user_id', $userId)->where('table_key', $key)->update(['position' => $index]);
        }
    }

    public function render(): View
    {
        $pinnedKeys = array_flip($this->pinnedKeys);

        $coreTables = [
            [
                'key' => 'installed-products',
                'label' => 'Product Database',
                'description' => 'Product database from PDB workbook',
                'icon' => 'o-cube',
                'url' => route('installed-products'),
                'count' => Installation::query()->count(),
                'source' => 'MCBTSi PRODUCT DATABASE.xlsx',
            ],
            [
                'key' => 'service-requests',
                'label' => 'Service Requests',
                'description' => 'Current requests from Executive Dashboard',
                'icon' => 'o-inbox-stack',
                'url' => route('service-requests'),
                'count' => ServiceRequest::query()->count(),
                'source' => 'MCBTSI_Executive_Dashboard_Updated.xlsx',
            ],
            [
                'key' => 'technical-reports',
                'label' => 'Technical Reports',
                'description' => 'Technical reports from Executive Dashboard',
                'icon' => 'o-document-text',
                'url' => route('technical-reports'),
                'count' => TechnicalReport::query()->count(),
                'source' => 'MCBTSI_Executive_Dashboard_Updated.xlsx',
            ],
            [
                'key' => 'history-reports',
                'label' => 'History Reports',
                'description' => 'Historical TSMS responses',
                'icon' => 'o-archive-box',
                'url' => route('history-reports'),
                'count' => HistoricalTsmsReport::query()->count(),
                'source' => 'MCBTSi TSMS (Responses).xlsx',
            ],
            [
                'key' => 'personnel',
                'label' => 'Technical Personnel',
                'description' => 'Company technical personnel',
                'icon' => 'o-user-group',
                'url' => route('personnel'),
                'count' => TechnicalPersonnel::query()->count(),
                'source' => 'Personnel list_.xlsx',
            ],
        ];

        $dynamicTables = DynamicTable::query()->orderBy('name')->get()->map(function (DynamicTable $table): array {
            return [
                'key' => $table->key,
                'label' => $table->name,
                'description' => (string) ($table->description ?: 'User-created table'),
                'icon' => $table->icon ?? 'o-table-cells',
                'url' => route('tables.show', ['table' => $table->key]),
                'count' => $table->rows()->count(),
                'source' => $table->monday_board_id ? 'monday.com board '.$table->monday_board_id : 'Manual',
            ];
        });

        $tables = collect(array_merge($coreTables, $dynamicTables->all()))
            ->map(fn (array $table): array => $table + ['pinned' => isset($pinnedKeys[$table['key']])])
            ->all();

        $imports = ImportBatch::query()->latest()->limit(10)->get();
        $recentEdits = RecordEditLog::query()->with('user:id,name')->latest()->limit(12)->get();

        return view('livewire.tables-list', [
            'tables' => $tables,
            'pinnedCount' => count($this->pinnedKeys),
            'imports' => $imports,
            'recentEdits' => $recentEdits,
            'columnTypeOptions' => app(ColumnTypeRegistry::class)->all(),
        ])->layout('layouts.dashboard')->title('Tables');
    }
}
