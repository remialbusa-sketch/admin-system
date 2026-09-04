<?php

namespace App\Notifications;

use App\Enums\UserPermission;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Internal handoff email sent by the Superadmin when they create an account
 * through the Users page. The account is already verified (the Superadmin's
 * action is the implicit trust), so this notification is purely informational:
 * it gives the new user their login URL, role, permission level, region, and
 * a one-time password the Superadmin handed out of band.
 *
 * Queued so a slow SMTP server does not block the Livewire request. If the
 * mailer is down, the queue will retry; UserManagement::createUser wraps the
 * dispatch in a try/catch so the account is still created either way.
 */
class AccountCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public User $user,
        public UserRole $role,
        public UserPermission $permission,
        public ?string $region,
        public string $plainPassword,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $loginUrl = route('login');
        $forgotUrl = route('password.request');
        $roleLabel = $this->role->label();
        $permissionLabel = $this->permission->label();
        $permissionDescription = $this->permission->description();
        $regionLine = $this->region ? "Region: {$this->region}" : null;

        $mail = (new MailMessage)
            ->subject($this->plainPassword === ''
                ? "Your {$roleLabel} account on ".config('app.name')
                : "Your {$roleLabel} account on ".config('app.name')." is ready")
            ->greeting("Hi {$this->user->name},")
            ->line($this->plainPassword === ''
                ? 'A Superadmin has resent your account details on '.config('app.name').'. Your email is already verified.'
                : 'A Superadmin has created an account for you on '.config('app.name').'. You can sign in right away — your email is already verified.')
            ->line("**Role:** {$roleLabel}")
            ->line("**Permission level:** {$permissionLabel} ({$permissionDescription})");

        if ($regionLine !== null) {
            $mail->line($regionLine);
        }

        if ($this->plainPassword !== '') {
            $mail
                ->line('**One-time password** (you will be asked to change it on first sign-in):')
                ->line('`'.$this->plainPassword.'`');
        } else {
            $mail
                ->line('If you have forgotten your current password, use the link below to set a new one.')
                ->action('Reset your password', $forgotUrl);
        }

        $mail
            ->action('Sign in', $loginUrl)
            ->line('If you did not expect this email, please reply to your Superadmin.');

        return $mail;
    }
}