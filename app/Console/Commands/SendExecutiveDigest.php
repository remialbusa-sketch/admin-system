<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Mail\ExecutiveDigest;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendExecutiveDigest extends Command
{
    protected $signature = 'app:send-exec-digest {--region= : Limit the digest to one region (default: all regions)}';

    protected $description = 'Email the weekly executive summary to leadership (President, VP Operations, Superadmin)';

    public function handle(): int
    {
        $scope = $this->option('region') ?: 'All regions';
        $scopeRegion = $scope === 'All regions' ? null : $scope;

        $payload = $this->buildPayload($scopeRegion, $scope);

        $recipients = User::query()
            ->whereIn('role', [UserRole::President, UserRole::VpOperations, UserRole::Superadmin])
            ->pluck('email');

        if ($recipients->isEmpty()) {
            $this->warn('No leadership accounts (President / VP Operations / Superadmin) to send the digest to.');

            return self::SUCCESS;
        }

        foreach ($recipients as $email) {
            Mail::to($email)->send(new ExecutiveDigest($payload, $scope));
        }

        $this->info("Digest sent to {$recipients->count()} recipient(s) — scope: {$scope}.");

        return self::SUCCESS;
    }

    /**
     * The digest keeps its own compact computation (a handful of aggregate
     * queries) so it stays a leadership OPS summary even though the Home
     * dashboard is now a Product Database overview.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(?string $scopeRegion, string $scope): array
    {
        $srScope = fn () => ServiceRequest::query()
            ->when($scopeRegion, fn ($q) => $q->where('region', $scopeRegion));

        $totalRequests = (int) $srScope()->count();
        $completed = (int) $srScope()->whereIn('group_status', ['Completed'])->count();
        $open = (int) $srScope()
            ->where(function ($q): void {
                $q->whereNotIn('group_status', ['Completed', 'Rejected'])->orWhereNull('group_status');
            })
            ->count();
        $completionRate = $totalRequests > 0 ? round(($completed / $totalRequests) * 100, 1) : 0;

        $productScope = fn () => Installation::query()
            ->when($scopeRegion, fn ($q) => $q->whereHas('account', fn ($a) => $a->where('region', $scopeRegion)));

        $installed = (int) $productScope()->count();
        $active = (int) $productScope()->whereRaw("lower(trim(device_status)) = 'active'")->count();
        $warrantyCovered = (int) $productScope()->whereRaw("lower(trim(warranty_status)) = 'yes'")->count();

        $warrantyOutlook = $productScope()->whereNotNull('warranty_end_date')
            ->selectRaw('SUM(CASE WHEN warranty_end_date >= ? AND warranty_end_date <= ? THEN 1 ELSE 0 END) as expiring', [now()->toDateString(), now()->addDays(90)->toDateString()])
            ->first();

        $regions = collect(['NCR', 'North Luzon', 'Visayas', 'Mindanao'])
            ->filter(fn (string $region) => $scopeRegion === null || $region === $scopeRegion)
            ->map(function (string $region) use ($srScope): array {
                $open = $srScope()
                    ->where('region', $region)
                    ->where(function ($q): void {
                        $q->whereNotIn('group_status', ['Completed', 'Rejected'])->orWhereNull('group_status');
                    })
                    ->count();

                return ['region' => $region, 'open' => $open];
            })->all();

        $latestBatch = DB::table('import_batches')
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->first();
        $freshness = $latestBatch?->completed_at
            ? Carbon::parse($latestBatch->completed_at)->format('M j, Y H:i')
            : 'no imports yet';

        return [
            'ops' => [
                'total_requests' => $totalRequests,
                'open_requests' => $open,
                'completion_rate' => $completionRate,
            ],
            'products' => [
                'installed' => $installed,
                'active' => $active,
                'warranty_covered' => $warrantyCovered,
                'warranty_expiring_90d' => (int) ($warrantyOutlook->expiring ?? 0),
            ],
            'regions' => $regions,
            'freshness' => $freshness,
            'scope' => $scope,
        ];
    }
}
