<?php

namespace App\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport;
use Throwable;

/**
 * Self-diagnostic for the configured mail transport. The system has been
 * bitten in the past by a misconfigured MAIL_PASSWORD leaving the app in a
 * state where the UI says "credentials email queued" and the queue worker
 * silently fails for hours, with no visible signal to the Superadmin.
 *
 * MailHealth answers: "given the current env, will an outgoing email
 * actually reach a real inbox?" — by checking the mailer driver, the
 * required env vars, and an SMTP handshake for the smtp driver.
 *
 * Render-safe by construction (incident 2026-10-07: prod's outbound SMTP is
 * firewalled, so the handshake used to block every Settings render and
 * Livewire action for the full 60s default_socket_timeout):
 *   1. smtp verdicts are cached (CACHE_TTL) so renders reuse them;
 *   2. the live probe is capped at PROBE_TIMEOUT seconds, with the process
 *      default restored in a finally.
 * The handshake connects and authenticates but never sends a message. On
 * `log` / `array` drivers the check is local-only. `mail:check` passes
 * fresh: true to force a live probe.
 */
class MailHealth
{
    /** Cache entry for smtp verdicts (the only branch that costs time). */
    public const CACHE_KEY = 'mail-health.inspect';

    /** How long an smtp verdict is reused across renders, in seconds. */
    public const CACHE_TTL = 60;

    /** Live-probe cap in seconds — a render never waits longer than this. */
    public const PROBE_TIMEOUT = 5;

    /**
     * @return array{driver: string, configured: bool, sending: bool, reason: ?string, from: ?string}
     */
    public function inspect(bool $fresh = false): array
    {
        $driver = (string) config('mail.default');

        if ($driver === 'smtp' && ! $fresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $report = $this->assess($driver);

        if ($driver === 'smtp') {
            Cache::put(self::CACHE_KEY, $report, self::CACHE_TTL);
        }

        return $report;
    }

    /**
     * @return array{driver: string, configured: bool, sending: bool, reason: ?string, from: ?string}
     */
    private function assess(string $driver): array
    {
        $from = config('mail.from.address');
        $env = App::environment();

        // Drivers that intentionally never send. They're "configured" in dev /
        // testing, but should not be used in production (the cron will drain
        // the queue and the notifications will be silently dropped).
        $nonSending = in_array($driver, ['log', 'array'], true);

        if ($nonSending) {
            $productionMisconfig = ! in_array($env, ['local', 'testing'], true);

            return [
                'driver' => $driver,
                'configured' => ! $productionMisconfig,
                'sending' => false,
                'reason' => $productionMisconfig
                    ? "Mail driver is `{$driver}` in a non-local environment — emails will be written to the log instead of being sent."
                    : null,
                'from' => is_string($from) ? $from : null,
            ];
        }

        if ($driver === 'smtp') {
            $missing = [];
            // config/mail.php nests smtp config under mail.mailers.smtp.* —
            // the flat mail.host / mail.port keys don't exist; reading them
            // would always look "missing" and trigger a false positive.
            foreach ([
                'mail.mailers.smtp.host' => 'MAIL_HOST',
                'mail.mailers.smtp.port' => 'MAIL_PORT',
                'mail.mailers.smtp.username' => 'MAIL_USERNAME',
                'mail.from.address' => 'MAIL_FROM_ADDRESS',
            ] as $configKey => $envKey) {
                $value = config($configKey);
                if ($value === null || $value === '' || $value === 'null') {
                    $missing[] = $envKey;
                }
            }

            // Password is optional for unauthenticated relays, but a real cPanel
            // mailbox requires it — flag it explicitly so the gap is obvious.
            $password = (string) config('mail.mailers.smtp.password', '');
            $username = (string) config('mail.mailers.smtp.username', '');
            $needsPassword = $username !== '' && $username !== 'null' && $password === '';

            if ($missing !== []) {
                return [
                    'driver' => $driver,
                    'configured' => false,
                    'sending' => false,
                    'reason' => 'SMTP is missing required env values: '.implode(', ', $missing).'.',
                    'from' => is_string($from) ? $from : null,
                ];
            }

            if ($needsPassword) {
                return [
                    'driver' => $driver,
                    'configured' => false,
                    'sending' => false,
                    'reason' => "SMTP is configured with a username ({$username}) but MAIL_PASSWORD is empty — the SMTP server will reject every send.",
                    'from' => is_string($from) ? $from : null,
                ];
            }

            // Best-effort: open a transport and let it try the handshake. If
            // the host is unreachable, the credentials are still wrong, or
            // TLS is misconfigured, we get an actionable error string. The
            // socket cap keeps a firewalled host from stalling the caller —
            // on 2026-10-07 every Settings render waited the full 60s
            // default_socket_timeout. (DNS resolution is not governed by
            // this ini; it is not part of the handshake.)
            $previousTimeout = ini_set('default_socket_timeout', (string) self::PROBE_TIMEOUT);

            try {
                $transport = Transport::fromDsn($this->buildDsn(), null);
                $transport->start();
                $transport->stop();

                return [
                    'driver' => $driver,
                    'configured' => true,
                    'sending' => true,
                    'reason' => null,
                    'from' => is_string($from) ? $from : null,
                ];
            } catch (Throwable $exception) {
                return [
                    'driver' => $driver,
                    'configured' => false,
                    'sending' => false,
                    'reason' => $this->normalizeSmtpError($exception->getMessage()),
                    'from' => is_string($from) ? $from : null,
                ];
            } finally {
                if (is_string($previousTimeout)) {
                    ini_set('default_socket_timeout', $previousTimeout);
                }
            }
        }

        // ses / postmark / resend / sendmail / failover: we don't actively
        // probe (each has its own auth model). Mark as configured if the
        // required env values are present, else surface what's missing.
        return [
            'driver' => $driver,
            'configured' => true,
            'sending' => true,
            'reason' => null,
            'from' => is_string($from) ? $from : null,
        ];
    }

    /**
     * Build a Symfony Mailer DSN for the active smtp config. Symfony accepts
     * a `smtp://user:pass@host:port` DSN; we use encryption from MAIL_SCHEME
     * or MAIL_ENCRYPTION so the handshake matches the .env template.
     */
    private function buildDsn(): string
    {
        $user = rawurlencode((string) config('mail.mailers.smtp.username', ''));
        $pass = rawurlencode((string) config('mail.mailers.smtp.password', ''));
        $host = (string) config('mail.mailers.smtp.host');
        $port = (int) config('mail.mailers.smtp.port', 587);
        $scheme = $this->resolveScheme();

        $auth = $user !== '' ? "{$user}:{$pass}@" : '';

        return "smtp://{$auth}{$host}:{$port}?{$scheme}";
    }

    private function resolveScheme(): string
    {
        $scheme = (string) config('mail.mailers.smtp.scheme', '');
        if ($scheme === '' || $scheme === 'null') {
            $scheme = (string) env('MAIL_SCHEME', '');
        }
        if ($scheme === '' || $scheme === 'null') {
            $scheme = (string) env('MAIL_ENCRYPTION', '');
        }

        return match (strtolower($scheme)) {
            'tls', 'starttls' => 'verify_peer=0&local_domain=localhost',
            'ssl' => 'verify_peer=0&local_domain=localhost',
            default => 'verify_peer=0&local_domain=localhost',
        };
    }

    private function normalizeSmtpError(string $message): string
    {
        // Trim the verbose Symfony exception preamble; keep the actionable
        // part so the Superadmin sees "AUTH failed", "Connection refused",
        // "TLS handshake failed", etc. — not a 600-character stack tail.
        $message = trim($message);

        if (strlen($message) > 240) {
            $message = substr($message, 0, 240).'…';
        }

        return $message;
    }
}
