<?php

namespace App\Console\Commands;

use App\Livewire\WidgetWizard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot deployment diagnostics: verifies the things that repeatedly bit us
 * on the shared host — pending migrations (the dashboards tables), a stale
 * optimized Composer classmap (new classes), built assets, and writable
 * runtime directories. Run after every `git pull`:
 *
 *     php artisan app:deploy-check
 */
class DeployCheckCommand extends Command
{
    protected $signature = 'app:deploy-check';

    protected $description = 'Verify the deployed code is complete: migrations, classmap, assets and permissions';

    public function handle(): int
    {
        $checks = [
            'dashboards tables migrated' => fn (): bool => Schema::hasTable('dashboards')
                && Schema::hasTable('dashboard_sources')
                && Schema::hasTable('dashboard_shares'),
            'dashboards soft-delete column' => fn (): bool => Schema::hasColumn('dashboards', 'deleted_at'),
            'dashboard audit table' => fn (): bool => Schema::hasTable('dashboard_audit_logs'),
            'WidgetWizard autoloadable (classmap)' => fn (): bool => class_exists(WidgetWizard::class),
            'built assets present (public/build)' => fn (): bool => is_file(public_path('build/manifest.json')),
            'storage/logs writable' => fn (): bool => is_writable(storage_path('logs')),
            'imports directory writable' => fn (): bool => ! is_dir(storage_path('app/private/imports'))
                || is_writable(storage_path('app/private/imports')),
        ];

        $failed = false;

        foreach ($checks as $label => $check) {
            try {
                $ok = $check();
            } catch (\Throwable) {
                $ok = false;
            }

            $this->line(($ok ? '<fg=green>OK</>' : '<fg=red>FAIL</>').'  '.$label);

            if (! $ok) {
                $failed = true;
            }
        }

        $pending = $this->pendingMigrations();

        if ($pending > 0) {
            $failed = true;
            $this->line('<fg=red>FAIL</>  '.$pending.' pending migration(s)');
        } else {
            $this->line('<fg=green>OK</>  no pending migrations');
        }

        if ($failed) {
            $this->newLine();
            $this->warn('Fix: composer dump-autoload -o && php artisan migrate --force && php artisan optimize:clear && touch public/index.php');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function pendingMigrations(): int
    {
        try {
            Artisan::call('migrate:status');

            return substr_count(Artisan::output(), 'Pending');
        } catch (\Throwable) {
            return 0;
        }
    }
}
