<?php

namespace Tests\Feature;

use App\Support\MailHealth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-07 Settings incident: on prod (smtp
 * driver, outbound SMTP firewalled) MailHealth::inspect() ran inside every
 * Settings render and Livewire action and blocked for the full
 * default_socket_timeout (60s) — the page took a minute to appear and the
 * Save/Test buttons looked dead. inspect() must reuse a cached verdict and
 * hard-cap the live probe.
 */
class MailHealthProbeTest extends TestCase
{
    /** @var resource|null silent SMTP listener for the bounded-probe test */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        parent::tearDown();
    }

    /**
     * smtp driver pointed at a closed local port — every probe is refused
     * instantly, so a re-probe shows up as a failing verdict.
     */
    private function useRefusedSmtp(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', '127.0.0.1');
        config()->set('mail.mailers.smtp.port', 1);
        config()->set('mail.mailers.smtp.username', 'user@example.com');
        config()->set('mail.mailers.smtp.password', 'secret');
        config()->set('mail.from.address', 'from@example.com');
    }

    public function test_inspect_returns_the_cached_verdict_without_probing(): void
    {
        $this->useRefusedSmtp();
        $cached = ['driver' => 'smtp', 'configured' => true, 'sending' => true, 'reason' => null, 'from' => 'from@example.com'];
        Cache::put(MailHealth::CACHE_KEY, $cached, MailHealth::CACHE_TTL);

        // A re-probe would hit the refused connection and return a failing
        // verdict instead of the cached one.
        $this->assertSame($cached, app(MailHealth::class)->inspect());
    }

    public function test_inspect_caches_the_probed_verdict_for_the_next_call(): void
    {
        $this->useRefusedSmtp();

        $first = app(MailHealth::class)->inspect();

        $this->assertFalse($first['configured']);
        $this->assertSame($first, Cache::get(MailHealth::CACHE_KEY));
        $this->assertSame($first, app(MailHealth::class)->inspect());
    }

    public function test_inspect_fresh_bypasses_the_cached_verdict(): void
    {
        $this->useRefusedSmtp();
        $cached = ['driver' => 'smtp', 'configured' => true, 'sending' => true, 'reason' => null, 'from' => 'from@example.com'];
        Cache::put(MailHealth::CACHE_KEY, $cached, MailHealth::CACHE_TTL);

        $report = app(MailHealth::class)->inspect(fresh: true);

        $this->assertNotSame($cached, $report);
        $this->assertFalse($report['configured']);
        $this->assertSame($report, Cache::get(MailHealth::CACHE_KEY));
    }

    public function test_smtp_probe_is_time_bounded_when_the_host_swallows_packets(): void
    {
        // A listener that accepts and never sends the SMTP greeting models a
        // firewalled host (prod: mail.mcbtsi.com drops every packet): the
        // handshake must give up at the cap, not at default_socket_timeout.
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($this->server);
        [$host, $port] = explode(':', (string) stream_socket_get_name($this->server, false));

        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', $host);
        config()->set('mail.mailers.smtp.port', (int) $port);
        config()->set('mail.mailers.smtp.username', 'user@example.com');
        config()->set('mail.mailers.smtp.password', 'secret');
        config()->set('mail.from.address', 'from@example.com');

        $before = (string) ini_get('default_socket_timeout');
        $t0 = microtime(true);
        $report = app(MailHealth::class)->inspect(fresh: true);
        $elapsed = microtime(true) - $t0;

        $this->assertFalse($report['configured']);
        $this->assertNotNull($report['reason']);
        $this->assertLessThan(15, $elapsed, "probe hung {$elapsed}s — the cap is not applied");
        $this->assertSame($before, (string) ini_get('default_socket_timeout'), 'default_socket_timeout must be restored after the probe');
    }
}
