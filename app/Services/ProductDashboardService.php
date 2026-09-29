<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Installation;
use App\Support\ChartPalette;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Home dashboard is a PRODUCT DATABASE overview: installed base, warranty
 * and contract posture, fleet state, brands/machine mix, and installation
 * momentum — computed only from imported installations (via their accounts
 * for region scope). Operations metrics (service requests, reports, history)
 * live on their own tables/analytics pages.
 */
class ProductDashboardService
{
    public const REGIONS = ['NCR', 'North Luzon', 'Visayas', 'Mindanao'];

    public const PERIODS = ['6M' => 6, '12M' => 12];

    private const CACHE_TTL_MINUTES = 5;

    /**
     * The summary costs ~20 queries; its inputs only change when a Product
     * Database import runs (SourceWorkbookImportService drops these keys),
     * when the dedupe collapses rows, or when a Superadmin edits a record by
     * hand (bounded by the short TTL). Cached per region scope + period.
     */
    public function summary(?string $region = 'All regions', string $period = '12M', array $filters = []): array
    {
        $isAll = ! $region || $region === 'All regions';
        $months = self::PERIODS[$period] ?? self::PERIODS['12M'];
        $filterHash = md5(json_encode($filters));
        $key = 'product:summary:'.($isAll ? 'all' : $region).':'.$months.':'.$filterHash;

        return Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES), fn (): array => $this->computeSummary($region, $months, $filters));
    }

    private function computeSummary(?string $region, int $months, array $filters = []): array
    {
        $isAll = ! $region || $region === 'All regions';
        $scopeRegion = $isAll ? null : $region;

        // Canonical, casing-safe fleet buckets (workbook values vary in case).
        $base = Installation::query()
            ->when($scopeRegion, fn ($q) => $q->whereHas('account', fn ($a) => $a->where('region', $scopeRegion)))
            ->when($filters['branch'] ?? null, fn ($q, $branch) => $q->whereHas('account', fn ($a) => $a->where('branch', $branch)))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->whereRaw('lower(trim(device_status)) = ?', [strtolower(trim((string) $status))]))
            ->when($filters['date_from'] ?? null, fn ($q, $from) => $q->where('installation_date', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($q, $to) => $q->where('installation_date', '<=', $to));

        $totalProducts = (int) (clone $base)->count();
        $activeProducts = (int) (clone $base)->whereRaw("lower(trim(device_status)) = 'active'")->count();
        $pulledOut = (int) (clone $base)->whereRaw("lower(replace(trim(device_status), ' ', '')) = 'pulledout'")->count();
        $warrantyCovered = (int) (clone $base)->whereRaw("lower(trim(warranty_status)) = 'yes'")->count();
        $contracts = (int) (clone $base)->whereRaw("lower(trim(service_contract_status)) in ('yes', 'renewal')")->count();
        $missingPms = (int) (clone $base)->whereNull('pms_frequency')->count();
        $annualBuCharge = (float) (clone $base)->sum('annual_bu_charge');
        $accountCount = (int) Account::query()
            ->when($scopeRegion, fn ($q) => $q->where('region', $scopeRegion))
            ->count();

        $today = now()->toDateString();
        $in90d = now()->addDays(90)->toDateString();
        $warrantyEnds = (clone $base)->whereNotNull('warranty_end_date')
            ->selectRaw('SUM(CASE WHEN warranty_end_date >= ? AND warranty_end_date <= ? THEN 1 ELSE 0 END) as expiring, SUM(CASE WHEN warranty_end_date < ? THEN 1 ELSE 0 END) as expired', [$today, $in90d, $today])
            ->first();
        $warrantyExpiring = (int) ($warrantyEnds->expiring ?? 0);
        $warrantyExpired = (int) ($warrantyEnds->expired ?? 0);

        // SLA countdown queue: the soonest warranty end dates still ahead of
        // us, region-scoped. Feeds the dashboard grid's SLA countdown widget.
        $slaUpcoming = (clone $base)
            ->whereNotNull('warranty_end_date')
            ->where('warranty_end_date', '>=', $today)
            ->where('warranty_end_date', '<=', $in90d)
            ->join('accounts', 'accounts.id', '=', 'installations.account_id')
            ->orderBy('warranty_end_date')
            ->limit(5)
            ->get(['installations.machine_type', 'installations.brand', 'installations.warranty_end_date', 'accounts.customer_name'])
            ->map(fn ($row): array => [
                'label' => trim(($row->customer_name ?: 'Unnamed account').' · '.($row->machine_type ?: ($row->brand ?: 'Equipment'))),
                'due' => $row->warranty_end_date->copy()->endOfDay(),
                'href' => route('installed-products', ['warranty' => 'expiring_90d']),
            ])->all();

        $activeRatio = $totalProducts > 0 ? round(($activeProducts / $totalProducts) * 100, 1) : 0;
        $warrantyRatio = $totalProducts > 0 ? round(($warrantyCovered / $totalProducts) * 100, 1) : 0;
        $missingPmsRatio = $totalProducts > 0 ? round(($missingPms / $totalProducts) * 100, 1) : 0;

        // Data-driven RAG thresholds (measurable, not subjective).
        $ragActive = $activeRatio >= 90 ? 'green' : ($activeRatio >= 75 ? 'amber' : 'red');
        $ragWarranty = $warrantyRatio >= 50 ? 'green' : ($warrantyRatio >= 30 ? 'amber' : 'red');
        $ragPms = $missingPms === 0 ? 'green' : ($missingPmsRatio <= 10 ? 'amber' : 'red');
        $ragWarrantyOutlook = $warrantyExpired === 0 ? 'green' : ($warrantyExpiring <= 25 ? 'amber' : 'red');

        // Installation momentum over the REAL business date (installation_date),
        // window-over-window comparison for the delta chip.
        $trendFrom = Carbon::today()->subMonths($months - 1)->startOfMonth();
        $installScope = fn () => (clone $base)->whereNotNull('installation_date');
        // Portable month-key: SQLite's strftime / MySQL's DATE_FORMAT.
        $monthExpr = config('database.default') === 'sqlite' ? "strftime('%Y-%m', installation_date)" : "DATE_FORMAT(installation_date, '%Y-%m')";
        $trendCounts = $installScope()
            ->where('installation_date', '>=', $trendFrom)
            ->selectRaw("{$monthExpr} as ym, COUNT(*) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $installTrend = collect(range($months - 1, 0))->map(function (int $monthsAgo) use ($trendCounts): array {
            $date = Carbon::today()->subMonths($monthsAgo);

            return [
                'label' => $date->format('M Y'),
                'short' => $date->format('M'),
                'count' => (int) ($trendCounts[$date->format('Y-m')] ?? 0),
                'href' => route('installed-products', ['installed' => $date->format('Y-m')]),
            ];
        })->all();

        $currentWindow = (int) $installScope()->where('installation_date', '>=', $trendFrom)->count();
        $prevWindow = (int) $installScope()
            ->where('installation_date', '>=', $trendFrom->copy()->subMonths($months))
            ->where('installation_date', '<', $trendFrom)
            ->count();
        $installDelta = $prevWindow > 0 ? round((($currentWindow - $prevWindow) / $prevWindow) * 100, 1) : null;

        // Regional position: one grouped query over installations + accounts.
        $regionRows = Installation::query()
            ->join('accounts', 'accounts.id', '=', 'installations.account_id')
            ->when($scopeRegion, fn ($q) => $q->where('accounts.region', $scopeRegion))
            ->when($filters['branch'] ?? null, fn ($q, $branch) => $q->where('accounts.branch', $branch))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->whereRaw('lower(trim(installations.device_status)) = ?', [strtolower(trim((string) $status))]))
            ->when($filters['date_from'] ?? null, fn ($q, $from) => $q->where('installations.installation_date', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($q, $to) => $q->where('installations.installation_date', '<=', $to))
            ->selectRaw("accounts.region as region,
                COUNT(*) as products,
                SUM(CASE WHEN lower(trim(installations.device_status)) = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN lower(trim(installations.warranty_status)) = 'yes' THEN 1 ELSE 0 END) as warranty")
            ->groupBy('accounts.region')
            ->get()
            ->keyBy('region');

        $regions = collect(self::REGIONS)->map(function (string $regionName) use ($regionRows): array {
            $row = $regionRows->get($regionName);

            return [
                'region' => $regionName,
                'products' => (int) ($row->products ?? 0),
                'active' => (int) ($row->active ?? 0),
                'warranty' => (int) ($row->warranty ?? 0),
                'href' => route('installed-products', ['region' => $regionName]),
            ];
        })->all();

        // Fleet state donut (≤4 segments + Other). Each segment deep-links to
        // the exact rows behind it (?status=…).
        $fleetRows = (clone $base)
            ->selectRaw("lower(replace(COALESCE(NULLIF(trim(device_status), ''), 'Other'), ' ', '')) as state, COUNT(*) as total")
            ->groupBy('state')->orderByDesc('total')->get();
        $fleetMap = [
            'active' => ['Active', 'success', 'Active'],
            'pulledout' => ['Pulled out', 'neutral', 'Pulledout'],
            'inactive' => ['Inactive', 'warning', 'Inactive'],
            'dysfunctional' => ['Dysfunctional', 'error', 'Dysfunctional'],
        ];
        $fleetDonut = [];
        foreach ($fleetMap as $key => [$label, $tone, $statusValue]) {
            $value = (int) ($fleetRows->firstWhere('state', $key)->total ?? 0);
            if ($value > 0) {
                $fleetDonut[] = ['label' => $label, 'value' => $value, 'tone' => $tone, 'href' => route('installed-products', ['status' => $statusValue])];
            }
        }
        $otherFleet = (int) $fleetRows->whereNotIn('state', array_keys($fleetMap))->sum('total');
        if ($otherFleet > 0) {
            $fleetDonut[] = ['label' => 'Other', 'value' => $otherFleet, 'tone' => 'neutral', 'href' => null];
        }

        // Leading brands donut (top 6 + Others). Real brands deep-link via ?brand=.
        // Each segment gets its own categorical color (unique per slice).
        $brandRows = (clone $base)
            ->whereNotNull('brand')->where('brand', '<>', '')
            ->selectRaw('brand, COUNT(*) as total')
            ->groupBy('brand')->orderByDesc('total')->limit(6)->get();
        $brandDonut = $brandRows->map(fn ($row, $i): array => [
            'label' => $row->brand,
            'value' => (int) $row->total,
            'tone' => 'primary',
            'color' => ChartPalette::color($i),
            'href' => route('installed-products', ['brand' => $row->brand]),
        ])->all();
        $brandOthers = (int) ((clone $base)->whereNotNull('brand')->where('brand', '<>', '')->count() - array_sum(array_column($brandDonut, 'value')));
        if ($brandOthers > 0) {
            $brandDonut[] = ['label' => 'Others', 'value' => $brandOthers, 'tone' => 'neutral', 'color' => ChartPalette::neutral(), 'href' => null];
        }

        // Machine-type mix (top 5 + Others). Real types deep-link via ?machine_type=.
        $typeRows = (clone $base)
            ->whereNotNull('machine_type')->where('machine_type', '<>', '')
            ->selectRaw('machine_type, COUNT(*) as total')
            ->groupBy('machine_type')->orderByDesc('total')->get();
        $machineTypes = $typeRows->take(5)->map(fn ($row): array => ['label' => $row->machine_type, 'total' => (int) $row->total, 'href' => route('installed-products', ['machine_type' => $row->machine_type])])->all();
        $typeOthers = (int) $typeRows->skip(5)->sum('total');
        if ($typeOthers > 0) {
            $machineTypes[] = ['label' => 'Others', 'total' => $typeOthers, 'href' => null];
        }

        // Top accounts by installed products.
        $topAccounts = Installation::query()
            ->join('accounts', 'accounts.id', '=', 'installations.account_id')
            ->when($scopeRegion, fn ($q) => $q->where('accounts.region', $scopeRegion))
            ->when($filters['branch'] ?? null, fn ($q, $branch) => $q->where('accounts.branch', $branch))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->whereRaw('lower(trim(installations.device_status)) = ?', [strtolower(trim((string) $status))]))
            ->when($filters['date_from'] ?? null, fn ($q, $from) => $q->where('installations.installation_date', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($q, $to) => $q->where('installations.installation_date', '<=', $to))
            ->selectRaw("accounts.customer_name as customer, accounts.region as region,
                COUNT(*) as products,
                SUM(CASE WHEN lower(trim(installations.device_status)) = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN lower(trim(installations.warranty_status)) = 'yes' THEN 1 ELSE 0 END) as warranty")
            ->groupBy('accounts.customer_name', 'accounts.region')
            ->orderByDesc('products')->limit(8)->get()
            ->map(fn ($row): array => [
                'customer' => $row->customer ?: 'Unnamed account',
                'region' => $row->region,
                'products' => (int) $row->products,
                'active' => (int) $row->active,
                'warranty' => (int) $row->warranty,
                'href' => route('installed-products', ['customer' => $row->customer ?: '']),
            ])->all();

        // Data freshness: latest completed Product Database import.
        $latestBatch = DB::table('import_batches')
            ->where('source_system', 'product_database')
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->first();
        $freshness = $latestBatch?->completed_at
            ? Carbon::parse($latestBatch->completed_at)->format('M j, Y H:i')
            : 'no imports yet';

        return [
            'regionOptions' => ['All regions', ...self::REGIONS],
            'freshness' => $freshness,
            // Status colors keyed by metric for headline widgets.
            'metric_rag' => [
                'installed' => 'green',
                'active' => $ragActive,
                'warranty_covered' => $ragWarranty,
                'contracts' => 'green',
                'annual_bu_charges' => 'green',
                'missing_pms' => $ragPms,
                'warranty_expiring_90d' => $ragWarrantyOutlook,
                'pulled_out' => 'green',
            ],
            // Scalar metrics exposed to the dashboard grid's expression
            // engine (ratios in percent, matching the RAG thresholds above).
            'metrics' => [
                'installed' => $totalProducts,
                'active' => $activeProducts,
                'pulled_out' => $pulledOut,
                'warranty_covered' => $warrantyCovered,
                'contracts' => $contracts,
                'missing_pms' => $missingPms,
                'annual_bu_charges' => round($annualBuCharge, 2),
                'accounts' => $accountCount,
                'warranty_expiring_90d' => $warrantyExpiring,
                'warranty_expired' => $warrantyExpired,
                'active_ratio' => $activeRatio,
                'warranty_ratio' => $warrantyRatio,
                'missing_pms_ratio' => $missingPmsRatio,
                'install_delta' => $installDelta,
            ],
            'sla' => $slaUpcoming,
            'regions' => $regions,
            'fleetDonut' => $fleetDonut,
            'brandDonut' => $brandDonut,
            'machineTypes' => $machineTypes,
            'topAccounts' => $topAccounts,
            'installTrend' => $installTrend,
            'installDelta' => $installDelta,
            'filters' => $filters,
        ];
    }
}
