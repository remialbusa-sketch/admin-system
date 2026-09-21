<?php

namespace App\Support;

use App\Models\Dashboard;
use App\Models\DashboardAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Writes dashboard audit entries for EDITS only (layout saves, shares,
 * sources, rename/delete/restore). Views are never logged.
 *
 * Fail-soft by design: a pulled-but-unmigrated deploy, or a logging failure,
 * must never break the dashboard action itself.
 */
class DashboardAudit
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function log(Dashboard|int|null $dashboard, string $action, array $meta = []): void
    {
        try {
            if (! Schema::hasTable('dashboard_audit_logs')) {
                return;
            }

            $dashboardId = $dashboard instanceof Dashboard ? $dashboard->getKey() : $dashboard;

            DashboardAuditLog::create([
                'dashboard_id' => $dashboardId,
                'user_id' => Auth::id(),
                'action' => $action,
                'meta' => $meta === [] ? null : $meta,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('dashboardAudit.failed', [
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
