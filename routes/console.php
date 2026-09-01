<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\SourceWorkbookImportService;
use Illuminate\Support\Str;

// Weekly leadership digest — Monday 07:00. Requires a working mailer
// (MAIL_MAILER=smtp in production; 'log' locally writes it to storage/logs).
Schedule::command('app:send-exec-digest')->weeklyOn(1, '07:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('source:import {source : product|requests|reports|tsms|personnel} {path}', function (string $source, string $path, SourceWorkbookImportService $importer): void {
    $method = match (Str::lower($source)) {
        'product' => 'importProductDatabase',
        'requests' => 'importServiceRequests',
        'reports' => 'importTechnicalReports',
        'tsms' => 'importHistoricalTsms',
        'personnel' => 'importPersonnel',
        default => null,
    };

    if ($method === null) {
        $this->error('Source must be product, requests, reports, or tsms.');

        return;
    }

    if (! is_file($path)) {
        $this->error("File not found: {$path}");

        return;
    }

    $batch = $importer->{$method}($path);

    $this->info("Batch #{$batch->id} {$batch->status}: {$batch->processed_rows} processed, {$batch->failed_rows} failed.");
})->purpose('Import one approved source workbook into its isolated domain');

Artisan::command('source:import-csv {source : product|requests|reports|tsms|personnel} {path}', function (string $source, string $path, SourceWorkbookImportService $importer): void {
    $method = match (Str::lower($source)) {
        'product' => 'importProductDatabase',
        'requests' => 'importServiceRequests',
        'reports' => 'importTechnicalReports',
        'tsms' => 'importHistoricalTsms',
        'personnel' => 'importPersonnel',
        default => null,
    };

    if ($method === null || ! is_file($path)) {
        $this->error('Provide a valid source and CSV path.');

        return;
    }

    $batch = $importer->{$method}($path);
    $this->info("Batch #{$batch->id} {$batch->status}: {$batch->processed_rows} processed, {$batch->failed_rows} failed.");
})->purpose('Import a prepared source CSV without loading an entire workbook');
