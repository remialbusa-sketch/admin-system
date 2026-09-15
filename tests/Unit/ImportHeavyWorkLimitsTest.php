<?php

namespace Tests\Unit;

use App\Services\SourceWorkbookImportService;
use Tests\TestCase;

class ImportHeavyWorkLimitsTest extends TestCase
{
    public function test_imports_lift_memory_and_time_limits_per_request(): void
    {
        $service = app(SourceWorkbookImportService::class);

        // Sanity: the defaults before the guard (CLI often runs with -1 or
        // 128M — whatever it is, remember it so the test can't lie).
        $before = ini_get('memory_limit');

        $method = new \ReflectionMethod($service, 'prepareForHeavyWork');
        $method->invoke($service);

        // The whole point of the guard: 512M so an 8k-row workbook cannot
        // exhaust memory on shared hosting (the live 503 root cause).
        $this->assertSame('512M', ini_get('memory_limit'));
        $this->assertSame(0, (int) ini_get('max_execution_time'));

        // Restore whatever the environment had, so other tests are not
        // affected by this process-wide change.
        ini_set('memory_limit', $before);
    }
}