<?php

namespace App\Services;

use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TspAnalyticsService
{
    private const REGIONS = ['NCR', 'North Luzon', 'Visayas', 'Mindanao'];

    /** Per-request memo for the TSP identity rollup. */
    private ?array $tspIdentityCache = null;

    public function summary(string $region = 'All regions'): array
    {
        // Counts come straight from the canonical columns the importer writes
        // (personnel.region, service_requests.region + normalized group_status)
        // instead of mapping branches in PHP with an exact-case lookup that
        // silently dropped unknown branches into 'Other'.
        $activeTspByRegion = TechnicalPersonnel::query()
            ->where(function ($query): void {
                $query->where('position', 'like', '%Service%')
                    ->orWhere('position', 'like', '%Field%')
                    ->orWhere('position', 'like', '%TSP%');
            })
            ->whereNotNull('region')
            ->selectRaw('region, COUNT(*) as total')
            ->groupBy('region')
            ->pluck('total', 'region')
            ->all();

        $totalActive = (int) array_sum($activeTspByRegion);

        $openByRegion = ServiceRequest::query()
            ->whereNotNull('region')
            ->whereIn('group_status', ['Open', 'In-Progress', 'For Continuation', 'For Escalation'])
            ->selectRaw('region, COUNT(*) as total')
            ->groupBy('region')
            ->pluck('total', 'region')
            ->all();

        $regionalData = collect(self::REGIONS)->map(function (string $regionName) use ($activeTspByRegion, $openByRegion): array {
            $open = (int) ($openByRegion[$regionName] ?? 0);

            return [
                'region' => $regionName,
                'active' => (int) ($activeTspByRegion[$regionName] ?? 0),
                'open' => $open,
            ];
        })->all();

        $visibleRegions = $region === 'All regions'
            ? $regionalData
            : collect($regionalData)->where('region', $region)->values()->all();

        $activeTsp = (int) collect($visibleRegions)->sum('active');
        $openRecords = (int) collect($visibleRegions)->sum('open');

        $totalReports = TechnicalReport::query()->count();
        $completedReports = TechnicalReport::query()->where('service_status', 'Completed')->count();
        $resolutionRate = $totalReports > 0 ? round(($completedReports / $totalReports) * 100, 1) : 0;

        $trend = collect(range(3, 0))->map(function (int $weeksAgo) {
            $start = Carbon::today()->subWeeks($weeksAgo + 1);
            $end = Carbon::today()->subWeeks($weeksAgo);
            $completed = TechnicalReport::query()
                ->whereNotNull('service_completed_at')
                ->whereBetween('service_completed_at', [$start, $end])
                ->count();

            return [
                'label' => $start->format('M d'),
                'resolved' => $completed,
            ];
        })->all();

        $kpis = [
            ['label' => 'Active TSPs', 'value' => number_format($activeTsp), 'context' => 'company service/field personnel', 'icon' => 'o-users', 'tone' => 'primary'],
            ['label' => 'Open records', 'value' => number_format($openRecords), 'context' => 'in selected scope', 'icon' => 'o-inbox-stack', 'tone' => 'info'],
            ['label' => 'Resolution rate', 'value' => $resolutionRate.'%', 'context' => 'completed technical reports', 'icon' => 'o-shield-check', 'tone' => 'success'],
            ['label' => 'Total reports', 'value' => number_format($totalReports), 'context' => 'technical report records', 'icon' => 'o-document-text', 'tone' => 'warning'],
        ];

        return [
            'kpis' => $kpis,
            'regionalData' => $visibleRegions,
            'trend' => $trend,
            'totalActive' => $totalActive,
        ];
    }

    public function details(?string $tspName = null, ?string $from = null, ?string $to = null, ?string $branch = null): array
    {
        $identities = $this->tspIdentities();

        $query = TechnicalReport::query()
            ->whereNotNull('tsp_name')
            ->where('tsp_name', '<>', '');

        if ($tspName !== null && $tspName !== '' && $tspName !== 'All TSPs') {
            // The dropdown value is a normalized person identity; expand it to
            // every raw workbook ID that belongs to that person so all of
            // their records are counted together.
            $ids = $identities['byKey'][mb_strtolower($tspName)]['ids'] ?? [$tspName];

            $query->where(function ($q) use ($ids, $tspName): void {
                $q->whereIn('tsp_name', $ids)->orWhere('tsp_name', $tspName);
            });
        }

        if ($from !== null && $from !== '') {
            $query->whereDate('service_started_at', '>=', $from);
        }
        if ($to !== null && $to !== '') {
            $query->whereDate('service_started_at', '<=', $to);
        }

        if ($branch !== null && $branch !== '' && $branch !== 'All branches') {
            $requestIds = ServiceRequest::query()
                ->where('branch', $branch)
                ->pluck('id')
                ->all();
            $query->whereIn('service_request_id', $requestIds);
        }

        $totalReports = (clone $query)->count();
        $completed = (clone $query)->where('service_status', 'Completed')->count();

        // Distinct TSPs counts normalized identities, not raw workbook IDs.
        $distinctTsp = (clone $query)
            ->distinct()
            ->pluck('tsp_name')
            ->map(fn ($id) => $identities['byId'][$id] ?? mb_strtolower((string) $id))
            ->unique()
            ->count();

        $avgRepair = (clone $query)->whereNotNull('repair_time_hours')->avg('repair_time_hours');

        $kpis = [
            ['label' => 'Reports (filtered)', 'value' => number_format($totalReports), 'context' => 'matching filters', 'icon' => 'o-document-text', 'tone' => 'primary'],
            ['label' => 'Distinct TSPs', 'value' => number_format($distinctTsp), 'context' => 'in current scope', 'icon' => 'o-users', 'tone' => 'info'],
            ['label' => 'Completion rate', 'value' => ($totalReports > 0 ? round(($completed / $totalReports) * 100, 1) : 0).'%', 'context' => 'completed reports', 'icon' => 'o-shield-check', 'tone' => 'success'],
            ['label' => 'Avg repair time', 'value' => round((float) $avgRepair, 2).'h', 'context' => 'per report', 'icon' => 'o-clock', 'tone' => 'warning'],
        ];

        // Per-TSP table merged by normalized identity so a person who appears
        // under several workbook IDs shows once with their full totals.
        $rows = (clone $query)
            ->selectRaw('tsp_name, COUNT(*) as reports, SUM(CASE WHEN service_status = ? THEN 1 ELSE 0 END) as completed, SUM(repair_time_hours) as repair_sum, COUNT(repair_time_hours) as repair_n', ['Completed'])
            ->groupBy('tsp_name')
            ->get();

        $merged = collect();

        foreach ($rows as $row) {
            $key = $identities['byId'][$row->tsp_name] ?? mb_strtolower((string) $row->tsp_name);
            $entry = $merged->get($key) ?? ['reports' => 0, 'completed' => 0, 'repair_sum' => 0.0, 'repair_n' => 0];

            $entry['reports'] += (int) $row->reports;
            $entry['completed'] += (int) $row->completed;
            $entry['repair_sum'] += (float) $row->repair_sum;
            $entry['repair_n'] += (int) $row->repair_n;

            $merged->put($key, $entry);
        }

        $top = $merged
            ->map(function (array $entry, string $key) use ($identities): array {
                $reports = $entry['reports'];
                $completed = $entry['completed'];
                $avg = $entry['repair_n'] > 0 ? $entry['repair_sum'] / $entry['repair_n'] : 0.0;

                return [
                    'tsp_name' => $identities['byKey'][$key]['label'] ?? $key,
                    'reports' => $reports,
                    'completed' => $completed,
                    'completion_rate' => $reports > 0 ? round(($completed / $reports) * 100, 1) : 0,
                    'avg_repair' => round($avg, 2),
                ];
            })
            ->sortByDesc('reports')
            ->take(25)
            ->values()
            ->all();

        return [
            'kpis' => $kpis,
            'topTsp' => $top,
            'filteredReports' => $totalReports,
        ];
    }

    public function branchOptions(): array
    {
        return ServiceRequest::query()
            ->whereNotNull('branch')
            ->where('branch', '<>', '')
            ->distinct()
            ->orderBy('branch')
            ->pluck('branch')
            ->all();
    }

    public function tspOptions(): array
    {
        // One option per normalized person identity (not per raw workbook
        // ID): the same human under several person-XXXX IDs merges into a
        // single entry, email-only display names become real names, and
        // repeated comma segments collapse.
        return collect($this->tspIdentities()['byKey'])
            ->sortBy(fn (array $entry): string => $entry['label'], SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn (array $entry): array => [$entry['label'] => $entry['label']])
            ->all();
    }

    /**
     * Normalize a raw workbook TSP value into a human-readable label:
     * trimmed whitespace, email-only values turned into names
     * ("franco.dagondon@mcbtsi.com" becomes "Franco Dagondon"), and
     * repeated comma-separated segments collapsed ("team-32875, team-32875").
     */
    public static function normalizeTspLabel(?string $raw): string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return '';
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        $segments = [];

        foreach (explode(',', $value) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            if (filter_var($segment, FILTER_VALIDATE_EMAIL)) {
                $local = str_replace(['.', '_', '-'], ' ', Str::before($segment, '@'));
                $segment = mb_convert_case(trim((string) preg_replace('/\s+/u', ' ', $local)), MB_CASE_TITLE, 'UTF-8');
            }

            $segments[] = $segment;
        }

        return implode(', ', array_values(array_unique($segments)));
    }

    /**
     * Roll raw workbook TSP IDs up to person-level identities.
     *
     * tsp_name holds a person-XXXX/team-XXXX ID and tsp_display_name a
     * free-text label; the same human appears under several IDs, some
     * display values are raw emails, and some team IDs have none at all.
     *
     * Returns:
     *   - byKey: normalized identity key => ["label" => string, "ids" => raw tsp_names]
     *   - byId:  raw tsp_name => identity key
     */
    private function tspIdentities(): array
    {
        if (is_array($this->tspIdentityCache)) {
            return $this->tspIdentityCache;
        }

        $variants = TechnicalReport::query()
            ->whereNotNull('tsp_name')->where('tsp_name', '<>', '')
            ->selectRaw("tsp_name, COALESCE(NULLIF(tsp_display_name, ''), tsp_name) as display, COUNT(*) as n")
            ->groupBy('tsp_name', 'display')
            ->get();

        $bestById = [];

        foreach ($variants as $row) {
            $label = self::normalizeTspLabel($row->display);

            if ($label === '') {
                continue;
            }

            $key = mb_strtolower($label);
            $bestById[$row->tsp_name][$key] = [
                'n' => ($bestById[$row->tsp_name][$key]['n'] ?? 0) + (int) $row->n,
                'label' => $label,
            ];
        }

        $byKey = [];
        $byId = [];

        foreach ($bestById as $id => $keys) {
            // The ID belongs to its most frequent normalized label, so a
            // person-ID with a name in most rows (and NULL in a few) still
            // resolves to the name, not the raw ID.
            uasort($keys, fn (array $a, array $b): int => $b['n'] <=> $a['n']);
            $idKey = (string) array_key_first($keys);
            $byId[$id] = $idKey;

            if (! isset($byKey[$idKey])) {
                $byKey[$idKey] = ['label' => $keys[$idKey]['label'], 'ids' => []];
            }

            $byKey[$idKey]['ids'][] = $id;
        }

        ksort($byKey);

        return $this->tspIdentityCache = ['byKey' => $byKey, 'byId' => $byId];
    }
}