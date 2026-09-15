<?php

namespace Tests\Feature;

use App\Support\MailHealth;
use Illuminate\Foundation\Application;
use Tests\TestCase;

/**
 * The mailer is the system that announces "your account is ready" to new
 * users. MailHealth is the single source of truth for "will this email
 * actually reach a real inbox?" — these tests pin its verdict for each
 * realistic configuration so a future .env tweak cannot silently
 * regress delivery to "log" on prod.
 */
class MailHealthTest extends TestCase
{
    /**
     * Laravel binds the detected env at app construction, so `config()->set('app.env', ...)`
     * does not affect `app()->environment()`. Force it via a shouldReceive
     * mock for the duration of the test.
     */
    private function forceEnvironment(string $env): void
    {
        $mock = \Mockery::mock(Application::class)->makePartial();
        $mock->shouldReceive('environment')->andReturn($env);
        $this->app->instance('app', $mock);
        $this->app->instance(Application::class, $mock);
        config()->set('app.env', $env);
    }

    public function test_log_driver_is_configured_in_local(): void
    {
        $this->forceEnvironment('local');
        config()->set('mail.default', 'log');

        $report = app(MailHealth::class)->inspect();

        $this->assertSame('log', $report['driver']);
        $this->assertTrue($report['configured']);
        $this->assertFalse($report['sending']);
        $this->assertNull($report['reason']);
    }

    public function test_log_driver_in_production_is_flagged_as_misconfigured(): void
    {
        // The whole point of this check: the prod app must NEVER use the log
        // driver. If it is, the Superadmin needs a clear, loud signal.
        $this->forceEnvironment('production');
        config()->set('mail.default', 'log');

        $report = app(MailHealth::class)->inspect();

        $this->assertFalse($report['configured']);
        $this->assertNotNull($report['reason']);
        $this->assertStringContainsString('log', $report['reason']);
        $this->assertStringContainsString('non-local', $report['reason']);
    }

    public function test_smtp_with_empty_password_is_flagged_as_misconfigured(): void
    {
        // The exact prod misconfig from today's incident: SMTP is set up,
        // username/host/port are correct, but the password field is empty.
        $this->forceEnvironment('production');
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'mail.mcbtsi.com');
        config()->set('mail.mailers.smtp.port', 587);
        config()->set('mail.mailers.smtp.username', 'admin@mcbtsi.com');
        config()->set('mail.mailers.smtp.password', '');
        config()->set('mail.from.address', 'admin@mcbtsi.com');

        $report = app(MailHealth::class)->inspect();

        $this->assertFalse($report['configured']);
        $this->assertStringContainsString('MAIL_PASSWORD', $report['reason']);
        $this->assertStringContainsString('admin@mcbtsi.com', $report['reason']);
    }

    public function test_smtp_missing_required_env_is_flagged_with_each_missing_key(): void
    {
        $this->forceEnvironment('production');
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', '');
        config()->set('mail.mailers.smtp.port', null);
        config()->set('mail.mailers.smtp.username', null);
        config()->set('mail.from.address', null);

        $report = app(MailHealth::class)->inspect();

        $this->assertFalse($report['configured']);
        $this->assertStringContainsString('MAIL_HOST', $report['reason']);
        $this->assertStringContainsString('MAIL_PORT', $report['reason']);
    }

    public function test_mail_check_command_reports_health_and_exits_zero_when_sending(): void
    {
        $this->forceEnvironment('local');
        config()->set('mail.default', 'log');

        $this->artisan('mail:check')
            ->expectsOutputToContain('driver=log')
            ->expectsOutputToContain('configured=yes')
            ->assertExitCode(0);
    }

    public function test_mail_check_command_exits_nonzero_on_misconfiguration(): void
    {
        $this->forceEnvironment('production');
        config()->set('mail.default', 'log');

        $this->artisan('mail:check')
            ->expectsOutputToContain('driver=log')
            ->expectsOutputToContain('configured=NO')
            ->assertExitCode(1);
    }
}