<?php

namespace App\Support;

use App\Services\ProductDashboardService;
use App\Services\TableAggregationService;
use App\Services\TechnicalServiceAnalysisService;
use Illuminate\Support\Facades\Cache;

/**
 * Shared dashboard cache invalidation helper.
 *
 * Ensures that whenever workbook imports, undo actions, or monday.com live
 * syncs modify underlying data, both the TableAggregationService version
 * and the specific dashboard summary caches (Home / Product Dashboard and
 * Technical Service Analysis) are dropped immediately.
 */
class DashboardCache
{
    /**
     * Invalidate all dashboard summaries and table aggregations.
     */
    public static function invalidateAll(): void
    {
        TableAggregationService::invalidate();
        self::invalidateProductSummary();
        self::invalidateTsaSummary();
    }

    /**
     * Invalidate dashboard caches relevant to a specific domain / table key.
     */
    public static function invalidateDomain(string $domain): void
    {
        TableAggregationService::invalidate();

        if ($domain === 'installed-products') {
            self::invalidateProductSummary();
        }

        if (in_array($domain, ['service-requests', 'technical-reports'], true)) {
            self::invalidateTsaSummary();
        }
    }

    /**
     * Clear product summary cache keys across all region scopes and periods.
     */
    public static function invalidateProductSummary(): void
    {
        $defaultHash = md5(json_encode([]));

        foreach ([...ProductDashboardService::REGIONS, 'all'] as $scope) {
            foreach (ProductDashboardService::PERIODS as $months) {
                Cache::forget('product:summary:'.$scope.':'.$months);
                Cache::forget('product:summary:'.$scope.':'.$months.':'.$defaultHash);
            }
        }
    }

    /**
     * Clear technical service analysis summary cache keys across all periods.
     */
    public static function invalidateTsaSummary(): void
    {
        foreach (TechnicalServiceAnalysisService::PERIODS as $days) {
            Cache::forget('tsa:summary:'.$days);
        }
    }
}
