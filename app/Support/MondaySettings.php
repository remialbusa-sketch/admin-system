<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * The single boundary for monday.com credential + kill-switch reads.
 *
 * DB wins over env: the superadmin Settings page stores the api token and
 * the global enable flag in system_settings; MONDAY_API_TOKEN /
 * MONDAY_SYNC_ENABLED remain as fallbacks for fresh installs and tests.
 * Every consumer (API client, scheduler, sync service, webhook job, table
 * pages) resolves through here — never read config('monday.*') directly for
 * these two values, or the Settings page silently stops working.
 */
class MondaySettings
{
    public const TOKEN_KEY = 'monday.api_token';

    public const ENABLED_KEY = 'monday.enabled';

    public static function token(): ?string
    {
        $db = SystemSetting::get(self::TOKEN_KEY);

        if ($db !== null && trim($db) !== '') {
            return trim($db);
        }

        $env = config('monday.token');

        return (is_string($env) && $env !== '') ? $env : null;
    }

    public static function enabled(): bool
    {
        $db = SystemSetting::get(self::ENABLED_KEY);

        if ($db !== null) {
            return in_array(strtolower($db), ['1', 'true', 'on'], true);
        }

        return (bool) config('monday.enabled', false);
    }

    /** Whether a usable token is configured (DB or env). */
    public static function configured(): bool
    {
        return self::token() !== null;
    }
}
