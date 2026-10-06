<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\SystemSetting;
use App\Services\MondayApiClient;
use App\Support\MailHealth;
use App\Support\MondaySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

class Settings extends Component
{
    #[Validate('required|email|max:255')]
    public string $testEmailAddress = '';

    /** monday.com personal API token (write-only — never echoed back). */
    public string $mondayToken = '';

    public bool $mondayShowToken = false;

    /** Global kill switch for the monday.com sync (DB-backed via MondaySettings). */
    public bool $mondayEnabled = false;

    public string $mondayMessage = '';

    public string $mondayMessageTone = 'info';

    public function mount(): void
    {
        // Default the test-email form to the signed-in user's own address.
        $user = auth()->user();
        if ($user !== null) {
            $this->testEmailAddress = (string) $user->email;
        }

        $this->mondayEnabled = MondaySettings::enabled();
    }

    public function render(MailHealth $mailHealth): View
    {
        return view('livewire.settings', [
            'mailHealth' => $mailHealth->inspect(),
            'mondayTokenSaved' => SystemSetting::get(MondaySettings::TOKEN_KEY) !== null,
        ])
            ->layout('layouts.dashboard')
            ->title('Settings');
    }

    /**
     * Send a one-line test email through the configured mail transport. The
     * goal is to confirm right now that an email will actually leave the
     * server — exactly the verification step the Superadmin needs after
     * setting MAIL_PASSWORD on a cPanel mailbox.
     */
    public function sendTestEmail(): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);

        $this->validate();

        $to = $this->testEmailAddress;

        try {
            Mail::raw(
                'This is a test email from '.config('app.name').".\n\n".
                'Driver: '.config('mail.default')."\n".
                'Host:   '.config('mail.host')."\n".
                'From:   '.config('mail.from.address')."\n".
                'Time:   '.now()->toDateTimeString()."\n\n".
                'If you got this, the mailer is working.',
                function ($message) use ($to): void {
                    $message->to($to)->subject('Test email from '.config('app.name'));
                }
            );

            session()->flash('test-email', "Test email sent to {$to}. Check the inbox (and the spam folder) — it can take up to a minute.");
        } catch (TransportException $exception) {
            Log::warning('Test email send failed (transport).', ['to' => $to, 'error' => $exception->getMessage()]);
            session()->flash('test-email-error', 'SMTP rejected the send: '.$exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Test email send failed.', ['to' => $to, 'error' => $exception->getMessage()]);
            session()->flash('test-email-error', 'Could not send the test email: '.$exception->getMessage());
        }
    }

    /**
     * Persist the monday.com credentials + global switch to system_settings.
     * The token input is write-only: blank keeps the already-saved token,
     * so re-saving the toggle never clobbers it.
     */
    public function saveMondaySettings(): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);

        $this->validate([
            'mondayToken' => ['nullable', 'string', 'max:255', Rule::notIn([' '])],
            'mondayEnabled' => ['boolean'],
        ]);

        $token = trim($this->mondayToken);

        if ($token !== '') {
            SystemSetting::set(MondaySettings::TOKEN_KEY, $token);
        }

        SystemSetting::set(MondaySettings::ENABLED_KEY, $this->mondayEnabled ? '1' : '0');

        $this->mondayToken = '';
        $this->mondayMessageTone = 'success';
        $this->mondayMessage = 'Saved. '.($token !== '' ? 'Token stored. ' : '').($this->mondayEnabled ? 'Sync enabled — connected boards pull new items every minute.' : 'Sync disabled — no pulls run until you re-enable it.');
    }

    /**
     * Verify the stored token right now: list the boards it can see. This is
     * the "is my token good?" check before connecting a table to a board.
     */
    public function testMondaySettings(): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);

        try {
            $boards = app(MondayApiClient::class)->boards();
            $this->mondayMessageTone = 'success';
            $this->mondayMessage = 'Connected — '.count($boards).' board(s) visible to this token.';
        } catch (Throwable $exception) {
            $this->mondayMessageTone = 'error';
            $this->mondayMessage = 'Connection failed: '.$exception->getMessage();
        }
    }
}
