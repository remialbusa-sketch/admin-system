<?php

namespace App\Support;

use Illuminate\Support\Facades\App;
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
 * required env vars, and a no-op SMTP handshake for the smtp driver.
 *
 * Cost: the smtp handshake opens a TCP connection to the host (with a
 * short timeout) but does NOT authenticate or send anything. On `log` /
 * `array` drivers the check is local-only. Safe to call from a UI render.
 */
class MailHealth
{
    /**
     * @return array{driver: string, configured: bool, sending: bool, reason: ?string, from: ?string}
     */
    public function inspect(): array
    {
        $driver = (string) config('mail.default');
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
            // TLS is misconfigured, we get an actionable error string.
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