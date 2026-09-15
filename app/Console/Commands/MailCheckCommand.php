<?php

namespace App\Console\Commands;

use App\Support\MailHealth;
use Illuminate\Console\Command;

/**
 * `php artisan mail:check` — CLI health check for the mail transport.
 *
 * Prints the active driver and the MailHealth verdict in a single line so a
 * cron job can mail the Superadmin on failure, and exits non-zero on
 * misconfiguration so monitoring can alert.
 *
 * Intended to be wired into the production cron before the schedule run:
 *   * * * * * cd /home/mcbtsjq1/admin-system && php artisan mail:check || mail -s "Admin mailer misconfigured" admin@mcbtsi.com < /dev/null
 */
class MailCheckCommand extends Command
{
    protected $signature = 'mail:check';

    protected $description = 'Verify the configured mail transport is sending mail (or intentionally log/array in local).';

    public function handle(MailHealth $health): int
    {
        $report = $health->inspect();

        $this->line(sprintf(
            'driver=%s configured=%s sending=%s from=%s',
            $report['driver'],
            $report['configured'] ? 'yes' : 'NO',
            $report['sending'] ? 'yes' : 'no',
            $report['from'] ?? '(none)',
        ));

        if ($report['reason'] !== null) {
            $this->error($report['reason']);
        }

        return $report['configured'] && $report['sending'] ? self::SUCCESS : self::FAILURE;
    }
}