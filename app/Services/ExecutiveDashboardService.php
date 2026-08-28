<?php

namespace App\Services;

use App\Models\HistoricalTsmsReport;
use App\Models\ImportBatch;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\TechnicalReport;
use Illuminate\Support\Carbon;

class ExecutiveDashboardService
{
    public function summary(): array
    {
        $totalRequests = ServiceRequest::query()->count();
        $openRequests = ServiceRequest::query()->whereNotIn('ticket_status', ['COMPLETED', 'Completed', 'RESOLVED', 'Resolved', 'CLOSED', 'Closed'])->count();
        $activeProducts = Installation::query()->whereIn('device_status', ['Active', 'ACTIVE'])->count();
        $historicalReports = HistoricalTsmsReport::query()->count();

        $statusRows = ServiceRequest::query()
            ->selectRaw('COALESCE(ticket_status, \'Unassigned\') as label, COUNT(*) as count')
            ->groupBy('ticket_status')
            ->orderByDesc('count')
            ->limit(8)
            ->get();
        $statusTotal = max(1, $statusRows->sum('count'));

        $trend = collect(range(6, 0))->map(function (int $daysAgo): array {
            $date = Carbon::today()->subDays($daysAgo);
            return [
                'day' => $date->format('D'),
                'value' => (int) ImportBatch::query()->whereDate('completed_at', $date)->sum('processed_rows'),
            ];
        })->all();
        $trendTotal = array_sum(array_column($trend, 'value'));

        return [
            'metrics' => [
                ['label' => 'Open service requests', 'value' => number_format($openRequests), 'change' => number_format($totalRequests).' imported', 'context' => 'total requests in system', 'tone' => $openRequests > 0 ? 'warning' : 'success', 'icon' => 'o-inbox-stack'],
                ['label' => 'Product database', 'value' => number_format($activeProducts), 'change' => number_format(Installation::query()->count()).' total', 'context' => 'equipment records imported', 'tone' => 'info', 'icon' => 'o-cube'],
                ['label' => 'Technical reports', 'value' => number_format(TechnicalReport::query()->count()), 'change' => $this->freshnessText(), 'context' => 'latest import', 'tone' => 'success', 'icon' => 'o-document-text'],
                ['label' => 'Historical TSMS', 'value' => number_format($historicalReports), 'change' => number_format(ImportBatch::query()->where('source_system', 'historical_tsms')->count()), 'context' => 'import batches on file', 'tone' => 'info', 'icon' => 'o-archive-box'],
            ],
            'statusSummary' => $statusRows->map(fn ($row): array => [
                'label' => $row->label,
                'count' => number_format($row->count),
                'share' => number_format(($row->count / $statusTotal) * 100, 1).'%',
                'tone' => $this->statusTone($row->label),
            ])->all(),
            'trend' => $trend,
            'trendTotal' => number_format($trendTotal),
            'attention' => [
                ['title' => number_format($openRequests).' service requests remain open', 'detail' => 'Review the current Service Requests table.', 'tone' => $openRequests > 0 ? 'warning' : 'success', 'icon' => 'o-clock'],
                ['title' => number_format(Installation::query()->whereNull('pms_frequency')->count()).' product database records have no PMS frequency', 'detail' => 'Superadmin can complete this field in Product Database.', 'tone' => 'warning', 'icon' => 'o-wrench-screwdriver'],
                ['title' => 'Source data status', 'detail' => $this->freshnessText(), 'tone' => 'success', 'icon' => 'o-check-circle'],
            ],
            'activity' => ImportBatch::query()->latest()->limit(5)->get()->map(fn (ImportBatch $batch): array => [
                'record' => strtoupper(str_replace('_', ' ', $batch->source_system)),
                'event' => "Import {$batch->status} ({$batch->processed_rows} rows)",
                'actor' => 'System',
                'time' => optional($batch->completed_at ?? $batch->created_at)->format('m/d H:i'),
            ])->all(),
            'freshness' => ImportBatch::query()->latest()->limit(10)->get(),
        ];
    }

    private function freshnessText(): string
    {
        $latest = ImportBatch::query()->latest('completed_at')->first();
        return $latest?->completed_at?->diffForHumans() ?? 'No completed imports yet';
    }

    /**
     * Map a service-request status label to a semantic badge tone (semantic,
     * not just neutral). Covers the full canonical set so no status falls back
     * to an ambiguous gray.
     */
    private function statusTone(?string $label): string
    {
        $norm = strtolower(trim((string) $label));

        return match (true) {
            $norm === '' || $label === null => 'neutral',
            in_array($norm, ['completed', 'resolved', 'closed', 'done'], true) => 'success',
            in_array($norm, ['open', 'new', 'unassigned'], true) => 'info',
            in_array($norm, ['in-progress', 'in progress', 'ongoing', 'for continuation'], true) => 'warning',
            in_array($norm, ['rejected', 'cancelled', 'canceled', 'for escalation', 'escalated'], true) => 'danger',
            default => 'neutral',
        };
    }
}