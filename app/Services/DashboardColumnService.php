<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\CustomTableColumn;
use App\Models\DynamicRow;
use App\Models\HistoricalTsmsReport;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use App\Support\MergedColumnValue;
use App\Support\TableCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** SQL-backed column charts and slicers share the same row scope on every table. */
class DashboardColumnService
{
    public const MAX_CATEGORIES = 500;

    public const MAX_SERIES = 64;

    private const MODELS = [
        'installed-products' => Installation::class,
        'service-requests' => ServiceRequest::class,
        'technical-reports' => TechnicalReport::class,
        'history-reports' => HistoricalTsmsReport::class,
        'personnel' => TechnicalPersonnel::class,
    ];

    private array $catalogs = [];

    /** Only real, chartable columns enter SQL; client-supplied identifiers never do. */
    public function columns(string $tableKey): array
    {
        if (isset($this->catalogs[$tableKey])) {
            return $this->catalogs[$tableKey];
        }

        abort_unless(collect(app(TableCatalog::class)->optionsFor(auth()->user()))->contains('key', $tableKey), 403);
        $model = new (self::MODELS[$tableKey] ?? DynamicRow::class);
        $casts = $model->getCasts();
        $columns = [];
        foreach ($model->getFillable() as $key) {
            if (in_array($key, ['raw_data', 'table_key', 'archived_at'], true)
                || ($model instanceof DynamicRow && $key === 'position')
                || str_ends_with($key, '_id') || str_starts_with($key, 'source_')) {
                continue;
            }
            $cast = $casts[$key] ?? '';
            $kind = str_contains($cast, 'date') ? 'date' : (preg_match('/^(decimal|float|double|integer)/', $cast) ? 'number' : 'text');
            $columns[$key] = ['label' => Str::headline($key), 'kind' => $kind, 'sql' => 'r.'.$key];
        }
        if ($model instanceof Installation) {
            foreach (['customer_name', 'customer_address', 'region', 'branch'] as $key) {
                $columns[$key] = ['label' => Str::headline($key), 'kind' => 'text', 'sql' => 'a.'.$key];
            }
        }
        $bindings = MergedColumnValue::bindings($tableKey, $model::class);
        $merged = [];
        foreach ($columns as $key => &$definition) {
            $destination = $model instanceof Installation && in_array($key, ['customer_name', 'customer_address', 'branch'], true) ? 'account.'.$key : $key;
            $sources = $bindings[$destination] ?? [];
            if ($sources !== []) {
                $definition['source_columns'] = $sources;
                $definition['sql'] = MergedColumnValue::sql(['type' => $definition['kind'], 'source_columns' => $sources,
                    'table_key' => $tableKey, 'canonical_key' => $destination], $definition['sql'], 'r.id');
                foreach ($sources as $source) {
                    $merged[$source['id']] = $key;
                }
            }
        }
        unset($definition);
        foreach (CustomTableColumn::query()->where('table_key', $tableKey)->orderBy('position')->get() as $column) {
            if (! in_array($column->type, ['text', 'long_text', 'number', 'date', 'status', 'dropdown', 'email', 'phone', 'link', 'checkbox'], true)) {
                continue;
            }
            $key = 'custom_'.$column->id;
            if (isset($merged[$column->id])) {
                // Keep saved chart/filter references working while the picker
                // offers the one canonical field for new configurations.
                $columns[$key] = $columns[$merged[$column->id]] + ['merged_into' => $merged[$column->id]];

                continue;
            }
            $kind = in_array($column->type, ['number', 'date'], true) ? $column->type : 'text';
            $shadow = match ($kind) {
                'number' => 'value_number', 'date' => 'value_date', default => 'value_text'
            };
            $columns[$key] = ['label' => $column->name.' (custom)', 'kind' => $kind,
                'sql' => 'c'.$column->id.'.'.$shadow, 'custom_id' => $column->id];
        }

        return $this->catalogs[$tableKey] = $columns;
    }

    public function options(array $sources, bool $numeric = false): array
    {
        $groups = [];
        foreach ($sources as $alias => $tableKey) {
            foreach ($this->columns($tableKey) as $key => $column) {
                if (! isset($column['merged_into']) && (! $numeric || $column['kind'] === 'number')) {
                    $groups[$alias][$alias.'.'.$key] = $column['label'];
                }
            }
        }

        return $groups;
    }

    public function field(string $qualified, array $sources): array
    {
        [$alias, $key] = array_pad(explode('.', $qualified, 2), 2, '');
        $tableKey = $sources[$alias] ?? null;
        $column = $tableKey ? ($this->columns($tableKey)[$key] ?? null) : null;
        if ($column === null) {
            throw ValidationException::withMessages(['column' => 'Choose a column from a connected table.']);
        }

        return [$tableKey, $key, $column];
    }

    public function validateProps(string $type, array $props, array $sources): void
    {
        [$tableKey, , $column] = $this->field((string) ($props['column'] ?? ''), $sources);
        $grouping = $type === 'slicer' ? ($props['mode'] ?? 'value') : ($props['grouping'] ?? 'value');
        $enums = $type === 'slicer' ? ['mode' => ['value', 'year', 'month', 'year_month', 'day']]
            : ['grouping' => ['value', 'year', 'month', 'year_month', 'day'], 'aggregation' => ['count', 'sum', 'avg', 'min', 'max'],
                'chart_type' => ['bar', 'line'], 'orientation' => ['horizontal', 'vertical'], 'sort' => ['category', 'value_desc']];
        foreach ($enums as $key => $allowed) {
            if (isset($props[$key]) && ! in_array($props[$key], $allowed, true)) {
                throw ValidationException::withMessages(['column' => 'Choose a valid '.Str::headline($key).'.']);
            }
        }
        $limitKey = $type === 'slicer' ? 'columns' : 'limit';
        $maximum = $type === 'slicer' ? 8 : self::MAX_CATEGORIES;
        if (isset($props[$limitKey]) && (filter_var($props[$limitKey], FILTER_VALIDATE_INT) === false || $props[$limitKey] < 1 || $props[$limitKey] > $maximum)) {
            throw ValidationException::withMessages(['column' => Str::headline($limitKey).' must be between 1 and '.$maximum.'.']);
        }
        if ($grouping !== 'value' && $column['kind'] !== 'date') {
            throw ValidationException::withMessages(['column' => 'Date grouping requires a date column. Use Value for a Year or Month column already in your table.']);
        }
        foreach (['series_column', 'value_column'] as $key) {
            if ($type !== 'column_chart' || empty($props[$key])) {
                continue;
            }
            [$otherTable, , $other] = $this->field((string) $props[$key], $sources);
            if ($otherTable !== $tableKey || ($key === 'value_column' && $other['kind'] !== 'number')) {
                throw ValidationException::withMessages(['column' => 'Chart columns must belong to the same table; the measured column must be numeric.']);
            }
        }
        if ($type === 'column_chart' && ($props['aggregation'] ?? 'count') !== 'count' && empty($props['value_column'])) {
            throw ValidationException::withMessages(['column' => 'Choose a numeric column for this calculation.']);
        }
    }

    /** A scoped projection keeps grouping portable between SQLite and MySQL. */
    public function rows(string $tableKey, array $needed, array $filters = []): Builder
    {
        $columns = $this->columns($tableKey);
        $model = new (self::MODELS[$tableKey] ?? DynamicRow::class);
        $table = $model->getTable();
        $query = DB::table($table.' as r')->select('r.id');
        if ($model instanceof DynamicRow) {
            $query->where('r.table_key', $tableKey)->whereNull('r.deleted_at');
        }
        if (Schema::hasColumn($table, 'archived_at')) {
            $query->whereNull('r.archived_at');
        }
        if ($model instanceof Installation) {
            $query->leftJoin('accounts as a', 'a.id', '=', 'r.account_id');
        }

        // These dimensions also make imported custom tables obey the filter bar.
        $dimensions = [];
        foreach ($columns as $key => $column) {
            $label = Str::lower(str_replace(' (custom)', '', $column['label']));
            foreach (['region', 'branch', 'status'] as $dimension) {
                if ($label === $dimension && ! isset($dimensions[$dimension])) {
                    $dimensions[$dimension] = $key;
                }
            }
        }
        $dimensions['status'] ??= match ($tableKey) {
            'installed-products' => 'device_status', 'service-requests' => 'group_status',
            'technical-reports' => 'service_status', 'personnel' => 'position', default => null,
        };
        $dateKey = match ($tableKey) {
            'installed-products' => 'installation_date', 'service-requests' => null,
            'technical-reports' => 'service_completed_at', 'history-reports' => 'response_timestamp', default => null,
        };
        // Dynamic tables use their first date column for the global date range.
        if ($model instanceof DynamicRow) {
            $dateKey = collect($columns)->search(fn ($column) => $column['kind'] === 'date') ?: null;
        }
        $slicers = array_filter($filters['slicers'] ?? [], fn ($s) => ($s['table_key'] ?? null) === $tableKey);
        $needed = array_unique([...$needed, ...array_column($slicers, 'key'), ...array_filter(array_values($dimensions)), ...array_filter([$dateKey])]);
        foreach ($needed as $key) {
            if (! isset($columns[$key])) {
                throw ValidationException::withMessages(['column' => 'This column is no longer available.']);
            }
            $column = $columns[$key];
            if (isset($column['custom_id'])) {
                $id = (int) $column['custom_id'];
                $query->leftJoin('table_custom_column_values as c'.$id, fn ($join) => $join
                    ->on('c'.$id.'.row_id', '=', 'r.id')->where('c'.$id.'.custom_column_id', $id));
            }
            $query->addSelect(isset($column['source_columns']) ? DB::raw($column['sql'].' as '.$key) : $column['sql'].' as '.$key);
        }
        if ($model instanceof ServiceRequest) {
            $query->addSelect('r.source_updated_at as scope_date');
            $dateKey = 'scope_date';
        }
        if ($model instanceof TechnicalReport) {
            $query->leftJoin('service_requests as sr', 'sr.id', '=', 'r.service_request_id')
                ->addSelect('sr.region as scope_region', 'sr.branch as scope_branch');
            $dimensions['region'] = 'scope_region';
            $dimensions['branch'] = 'scope_branch';
        }

        $rows = DB::query()->fromSub($query, 'dashboard_rows');
        $region = auth()->user()?->role === UserRole::RegionalManager ? auth()->user()->region : ($filters['region'] ?? null);
        if (auth()->user()?->role === UserRole::RegionalManager && (trim((string) $region) === '' || $region === 'All regions')) {
            $rows->whereRaw('1 = 0');
        }
        foreach (['region' => $region, 'branch' => $filters['branch'] ?? null, 'status' => $filters['status'] ?? null] as $dimension => $value) {
            if ($value === null || $value === '' || $value === 'All regions') {
                continue;
            }
            $key = $dimensions[$dimension] ?? null;
            if ($key !== null) {
                $rows->whereRaw('lower(trim('.$this->wrap($key).')) = ?', [Str::lower(trim($value))]);
            } elseif ($dimension === 'region' && auth()->user()?->role === UserRole::RegionalManager) {
                $rows->whereRaw('1 = 0'); // A table without a region cannot widen a locked scope.
            }
        }
        if ($dateKey !== null) {
            $rows->when($filters['date_from'] ?? null, fn ($q, $value) => $q->whereDate($dateKey, '>=', $value))
                ->when($filters['date_to'] ?? null, fn ($q, $value) => $q->whereDate($dateKey, '<=', $value));
        }
        foreach ($slicers as $slicer) {
            $expression = $this->bucket($slicer['key'], $slicer['mode'] ?? 'value');
            $rows->whereIn(DB::raw($expression), array_slice($slicer['values'] ?? [], 0, 100));
        }

        return $rows;
    }

    /** Add slicer constraints to existing metric/dataset queries without changing their contracts. */
    public function applySlicers($query, string $tableKey, array $filters): void
    {
        if (! collect($filters['slicers'] ?? [])->contains('table_key', $tableKey)) {
            return;
        }
        $model = new (self::MODELS[$tableKey] ?? DynamicRow::class);
        $query->whereIn($model->getTable().'.id', $this->rows($tableKey, [], $filters)->select('dashboard_rows.id'));
    }

    public function chart(array $props, array $sources, array $filters): array
    {
        $this->validateProps('column_chart', $props, $sources);
        [$tableKey, $key] = $this->field($props['column'], $sources);
        $seriesKey = ! empty($props['series_column']) ? $this->field($props['series_column'], $sources)[1] : null;
        $valueKey = ! empty($props['value_column']) ? $this->field($props['value_column'], $sources)[1] : null;
        $rows = $this->rows($tableKey, array_filter([$key, $seriesKey, $valueKey]), $filters);
        $category = $this->bucket($key, $props['grouping'] ?? 'value');
        $series = $seriesKey ? $this->bucket($seriesKey, 'value') : "'Total'";
        $calculation = in_array($props['aggregation'] ?? '', ['sum', 'avg', 'min', 'max'], true) ? $props['aggregation'] : 'count';
        $value = $calculation === 'count' ? 'COUNT(*)' : strtoupper($calculation).'('.$this->wrap($valueKey).')';
        $grouped = $rows->selectRaw($category.' as category, '.$series.' as series, '.$value.' as amount')->groupBy('category', 'series');
        $sort = $props['sort'] ?? 'category';
        $limit = min(self::MAX_CATEGORIES, max(1, (int) ($props['limit'] ?? 30)));
        // Limit categories after aggregation, retaining every series in the chosen categories.
        $totals = DB::query()->fromSub(clone $grouped, 'groups')->selectRaw('category, SUM(amount) as total')->groupBy('category');
        if ($sort === 'value_desc') {
            $totals->orderByDesc('total');
        }
        $categories = $totals->orderBy('category')->limit($limit + 1)->pluck('category')->all();
        $truncated = count($categories) > $limit;
        $categories = array_slice($categories, 0, $limit);
        $data = DB::query()->fromSub($grouped, 'groups')->whereIn('category', $categories)->orderBy('series')->get();
        $seriesNames = $data->pluck('series')->unique()->values()->all();
        // A visible warning accompanies caps; never silently drop categories or series.
        $seriesTruncated = count($seriesNames) > self::MAX_SERIES;
        $seriesNames = array_slice($seriesNames, 0, self::MAX_SERIES);
        $matrix = [];
        foreach ($data as $row) {
            $matrix[(string) $row->category][(string) $row->series] = $row->amount;
        }
        $points = [];
        foreach ($categories as $category) {
            $values = [];
            foreach ($seriesNames as $name) {
                $amount = $matrix[(string) $category][(string) $name] ?? null;
                $values[] = $amount === null && $calculation !== 'count' ? null : (float) ($amount ?? 0);
            }
            $points[] = ['label' => $this->label((string) $category, $props['grouping'] ?? 'value'), 'values' => $values];
        }

        return ['points' => $points, 'series' => array_map(fn ($s) => $s === '' ? '(Blank)' : $s, $seriesNames), 'truncated' => $truncated, 'seriesTruncated' => $seriesTruncated];
    }

    public function choices(array $props, array $sources, array $filters, string $id): array
    {
        $this->validateProps('slicer', $props, $sources);
        [$tableKey, $key] = $this->field($props['column'], $sources);
        $selected = $filters['slicers'][$id]['values'] ?? [];
        unset($filters['slicers'][$id]);
        $expression = $this->bucket($key, $props['mode'] ?? 'value');
        $rows = $this->rows($tableKey, [$key], $filters)->selectRaw($expression.' as choice, COUNT(*) as total')
            ->groupBy('choice')->orderBy('choice')->limit(501)->get();
        $truncated = $rows->count() > 500;
        $options = $rows->take(500)->map(fn ($r) => ['value' => (string) $r->choice, 'label' => $this->label((string) $r->choice, $props['mode'] ?? 'value'), 'count' => (int) $r->total])->keyBy('value')->all();
        foreach ($selected as $value) {
            $options[$value] ??= ['value' => $value, 'label' => $this->label($value, $props['mode'] ?? 'value'), 'count' => 0];
        }

        return ['options' => array_values($options), 'selected' => $selected, 'truncated' => $truncated];
    }

    private function wrap(string $key): string
    {
        return DB::connection()->getQueryGrammar()->wrap($key);
    }

    private function bucket(string $key, string $mode): string
    {
        $column = $this->wrap($key);
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $expression = match ($mode) {
            'year' => $sqlite ? "strftime('%Y', $column)" : "DATE_FORMAT($column, '%Y')",
            'month' => $sqlite ? "strftime('%m', $column)" : "DATE_FORMAT($column, '%m')",
            'year_month' => $sqlite ? "strftime('%Y-%m', $column)" : "DATE_FORMAT($column, '%Y-%m')",
            'day' => $sqlite ? "strftime('%Y-%m-%d', $column)" : "DATE_FORMAT($column, '%Y-%m-%d')",
            default => 'CAST('.$column.' AS '.($sqlite ? 'TEXT' : 'CHAR').')',
        };

        return "COALESCE($expression, '')";
    }

    private function label(string $value, string $mode): string
    {
        if ($value === '') {
            return '(Blank)';
        }
        if ($mode === 'month' && ctype_digit($value) && (int) $value >= 1 && (int) $value <= 12) {
            return date('F', mktime(0, 0, 0, (int) $value, 1));
        }

        return $value;
    }
}
