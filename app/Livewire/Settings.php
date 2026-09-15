<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Support\MailHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

class Settings extends Component
{
    #[Validate('required|email|max:255')]
    public string $testEmailAddress = '';

    public function mount(): void
    {
        // Default the test-email form to the signed-in user's own address.
        $user = auth()->user();
        if ($user !== null) {
            $this->testEmailAddress = (string) $user->email;
        }
    }

    public function render(MailHealth $mailHealth): View
    {
        return view('livewire.settings', [
            'mailHealth' => $mailHealth->inspect(),
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
                "This is a test email from ".config('app.name').".\n\n".
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
            session()->flash('test-email-error', "SMTP rejected the send: ".$exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Test email send failed.', ['to' => $to, 'error' => $exception->getMessage()]);
            session()->flash('test-email-error', "Could not send the test email: ".$exception->getMessage());
        }
    }
}