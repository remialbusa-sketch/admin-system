<?php

namespace App\Services;

use App\Models\TechnicalReport;
use App\Support\ChartPalette;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Technical Service Analysis is a TECHNICAL REPORTS overview: completion,
 * TSP workload, status mix, and brand patterns — computed only from imported
 * technical reports. Every widget deep-links back into the Technical Reports
 * grid (?status=, ?tsp=, ?brand=, ?completed=…) so the page always points at
 * its source of truth.
 */
class TechnicalServiceAnalysisService
{
    public const PERIODS = ['7D' => 7, '30D' => 30, '90D' => 90];

    private const CACHE_TTL_MINUTES = 5;

    public function summary(string $period = '30D'): array
    {
        $days = self::PERIODS[$period] ?? self::PERIODS['30D'];
        $key = 'tsa:summary:'.$days;

        return Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES), fn (): array => $this->computeSummary($days));
    }

    private function computeSummary(int $days): array
    {
        $totalReports = (int) TechnicalReport::query()->count();
        $completedAny = (int) TechnicalReport::query()->whereNotNull('service_completed_at')->count();
        $assignedTsp = (int) TechnicalReport::query()->whereNotNull('tsp_name')->where('tsp_name', '<>', '')->count();
        $unassigned = max(0, $totalReports - $assignedTsp);
        $avgRepair = (float) TechnicalReport::query()->whereNotNull('repair_time_hours')->average('repair_time_hours');
        $avgResponse = (float) TechnicalReport::query()->whereNotNull('response_time_hours')->average('response_time_hours');

        // Completion trend over the REAL business date (service_completed_at):
        // one grouped query, buckets summed in PHP (day buckets for ≤14 days,
        // weekly buckets beyond) — replaces the old per-day full scans.
        $trendFrom = Carbon::today()->subDays($days - 1)->startOfDay();
        $perDay = TechnicalReport::query()
            ->whereNotNull('service_completed_at')
            ->where('service_completed_at', '>=', $trendFrom)
            ->selectRaw('date(service_completed_at) as d, COUNT(*) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $bucketDays = $days <= 14 ? 1 : 7;
        $trend = [];
        $cursor = Carbon::today()->subDays($days - 1)->startOfDay();
        while ($cursor <= Carbon::today()->endOfDay()) {
            $bucketEnd = $cursor->copy()->addDays($bucketDays - 1)->endOfDay();
            $count = 0;
            for ($day = $cursor->copy(); $day <= $bucketEnd; $day->addDay()) {
                $count += (int) ($perDay[$day->toDateString()] ?? 0);
            }
            if ($count > 0 || $bucketDays === 1) {
                $trend[] = [
                    'label' => $bucketDays === 1 ? $cursor->format('M j') : $cursor->format('M j').'–'.$bucketEnd->format('M j'),
                    'count' => $count,
                    'href' => $bucketDays === 1
                        ? route('technical-reports', ['completed' => $cursor->toDateString()])
                        : route('technical-reports', ['completed_from' => $cursor->toDateString(), 'completed_to' => $bucketEnd->toDateString()]),
                ];
            }
            $cursor->addDays($bucketDays);
        }
        $trendTotal = array_sum(array_column($trend, 'count'));
        $trendMax = max(1, (int) collect($trend)->max('count'));

        // Status mix donut (top 4 + Other) + full breakdown, both drill via ?status=.
        $statusRows = TechnicalReport::query()
            ->selectRaw("COALESCE(NULLIF(service_status, ''), 'Unassigned') as label, COUNT(*) as total")
            ->groupBy('label')->orderByDesc('total')->get();
        $statusDonut = $statusRows->take(4)->map(fn ($row): array => [
            'label' => $row->label,
            'value' => (int) $row->total,
            'tone' => $this->statusTone($row->label),
            'href' => $row->label === 'Unassigned' ? null : route('technical-reports', ['status' => $row->label]),
        ])->all();
        $statusOthers = (int) $statusRows->skip(4)->sum('total');
        if ($statusOthers > 0) {
            $statusDonut[] = ['label' => 'Other', 'value' => $statusOthers, 'tone' => 'neutral', 'href' => null];
        }
        $statusTotal = max(1, array_sum(array_column($statusDonut, 'value')));
        $byStatus = $statusRows->map(fn ($row): array => [
            'label' => $row->label,
            'count' => (int) $row->total,
            'href' => $row->label === 'Unassigned' ? null : route('technical-reports', ['status' => $row->label]),
        ])->all();

        // TSP workload (top 8) — rows deep-link via ?tsp= (the stable ID);
        // the LABEL is the real display name (workbook IDs like person-777… mean nothing to people).
        $byTsp = TechnicalReport::query()
            ->whereNotNull('tsp_name')->where('tsp_name', '<>', '')
            ->selectRaw("tsp_name, MAX(COALESCE(NULLIF(tsp_display_name, ''), tsp_name)) as label, COUNT(*) as total")
            ->groupBy('tsp_name')->orderByDesc('total')->limit(8)->get()
            ->map(fn ($row): array => ['label' => $row->label ?: $row->tsp_name, 'total' => (int) $row->total, 'href' => route('technical-reports', ['tsp' => $row->tsp_name])])
            ->all();
        $tspMax = max(1, (int) collect($byTsp)->max('total'));

        // Brand mix donut (top 6 + Other) — segments deep-link via ?brand=.
        // Each segment gets its own categorical color (unique per slice).
        $brandRows = TechnicalReport::query()
            ->whereNotNull('brand')->where('brand', '<>', '')
            ->selectRaw('brand, COUNT(*) as total')
            ->groupBy('brand')->orderByDesc('total')->limit(6)->get();
        $brandDonut = $brandRows->map(fn ($row, $i): array => [
            'label' => $row->brand,
            'value' => (int) $row->total,
            'tone' => 'primary',
            'color' => ChartPalette::color($i),
            'href' => route('technical-reports', ['brand' => $row->brand]),
        ])->all();
        $brandOthers = (int) (TechnicalReport::query()->whereNotNull('brand')->where('brand', '<>', '')->count() - array_sum(array_column($brandDonut, 'value')));
        if ($brandOthers > 0) {
            $brandDonut[] = ['label' => 'Others', 'value' => $brandOthers, 'tone' => 'neutral', 'color' => ChartPalette::neutral(), 'href' => null];
        }
        $brandTotal = max(1, array_sum(array_column($brandDonut, 'value')));

        $kpis = [
            ['label' => 'Technical reports', 'value' => $totalReports, 'context' => 'imported service reports', 'tone' => 'primary', 'icon' => 'o-document-text', 'rag' => 'green', 'href' => route('technical-reports')],
            ['label' => 'Completed', 'value' => $completedAny, 'context' => 'have a completion time', 'tone' => 'success', 'icon' => 'o-check-circle', 'rag' => 'green', 'href' => route('technical-reports', ['completed' => 'any'])],
            ['label' => 'Assigned TSP', 'value' => $assignedTsp, 'context' => $unassigned.' unassigned', 'tone' => 'info', 'icon' => 'o-users', 'rag' => $unassigned > 0 ? 'amber' : 'green', 'href' => route('technical-reports', ['assigned' => '1'])],
            ['label' => 'Avg repair time', 'value' => $avgRepair, 'suffix' => 'h', 'decimals' => 1, 'context' => 'mean per report', 'tone' => 'warning', 'icon' => 'o-clock', 'rag' => 'green', 'href' => route('technical-reports')],
        ];

        $secondary = [
            ['label' => 'Avg response time', 'value' => $avgResponse, 'suffix' => 'h', 'decimals' => 1, 'context' => 'from report data', 'tone' => 'primary', 'icon' => 'o-clock', 'rag' => 'green', 'href' => route('technical-reports')],
            ['label' => 'Completed · '.$days.'d', 'value' => $trendTotal, 'context' => 'in the selected window', 'tone' => 'success', 'icon' => 'o-calendar', 'rag' => 'green', 'href' => route('technical-reports', ['completed_from' => $trendFrom->toDateString(), 'completed_to' => now()->toDateString()])],
            ['label' => 'Unassigned reports', 'value' => $unassigned, 'context' => 'no TSP on record', 'tone' => 'error', 'icon' => 'o-user-minus', 'rag' => $unassigned > 0 ? 'amber' : 'green', 'href' => route('technical-reports', ['assigned' => '0'])],
        ];

        // Data freshness: latest report update timestamp.
        $freshness = Carbon::parse(
            (string) (TechnicalReport::query()->max('updated_at') ?? ''),
        )->format('M j, Y H:i');

        return [
            'kpis' => $kpis,
            'secondary' => $secondary,
            'statusDonut' => $statusDonut,
            'statusTotal' => $statusTotal,
            'byStatus' => $byStatus,
            'trend' => $trend,
            'trendTotal' => $trendTotal,
            'trendMax' => $trendMax,
            'trendDays' => $days,
            'byTsp' => $byTsp,
            'tspMax' => $tspMax,
            'brandDonut' => $brandDonut,
            'brandTotal' => $brandTotal,
            'avgResponse' => $avgResponse,
            'freshness' => $freshness,
        ];
    }

    /**
     * Map a technical-report status label to a semantic tone.
     */
    private function statusTone(?string $label): string
    {
        $norm = strtolower(trim((string) $label));

        return match (true) {
            $norm === '' => 'neutral',
            str_contains($norm, 'complet') || str_contains($norm, 'done') || str_contains($norm, 'resolved') || str_contains($norm, 'closed') => 'success',
            str_contains($norm, 'progress') || str_contains($norm, 'ongoing') || str_contains($norm, 'continuation') => 'warning',
            str_contains($norm, 'reject') || str_contains($norm, 'cancel') || str_contains($norm, 'escalat') => 'error',
            default => 'neutral',
        };
    }
}
