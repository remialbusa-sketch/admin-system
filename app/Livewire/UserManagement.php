<?php

namespace App\Livewire;

use App\Enums\UserPermission;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;
use App\Services\ProductDashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * Superadmin-only account administration: create accounts, assign roles,
 * regions and permission levels, and reset passwords. Roles and permissions
 * are applied with forceFill — deliberately not mass-assignable elsewhere.
 */
class UserManagement extends Component
{
    public array $newUser = [
        'name' => '',
        'email' => '',
        'password' => '',
        'role' => 'regional_manager',
        'permission' => 'viewer',
        'region' => '',
    ];

    public ?int $editingId = null;

    public array $editing = [
        'role' => 'regional_manager',
        'permission' => 'viewer',
        'region' => '',
        'password' => '',
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);
    }

    public function render(): View
    {
        return view('livewire.user-management', [
            'users' => User::query()->orderBy('name')->get(),
            'roles' => collect(UserRole::cases())->map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ])->all(),
            'permissions' => collect(UserPermission::cases())->map(fn (UserPermission $permission) => [
                'value' => $permission->value,
                'label' => $permission->label(),
                'description' => $permission->description(),
            ])->all(),
            'regions' => ProductDashboardService::REGIONS,
        ])
            ->layout('layouts.dashboard')
            ->title('Users');
    }

    public function createUser(): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);

        $validated = $this->validate([
            'newUser.name' => ['required', 'string', 'max:255'],
            'newUser.email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class.',email'],
            'newUser.password' => ['required', 'string', Password::defaults()],
            'newUser.role' => ['required', Rule::in(array_column(UserRole::cases(), 'value'))],
            'newUser.permission' => ['required', Rule::in(array_column(UserPermission::cases(), 'value'))],
            'newUser.region' => ['nullable', Rule::in([...ProductDashboardService::REGIONS, ''])],
        ])['newUser'];

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        $user->forceFill([
            'role' => $validated['role'],
            // Superadmin accounts are always admin-level; a role choice cannot
            // be undermined by a low permission value.
            'permission' => $validated['role'] === UserRole::Superadmin->value
                ? UserPermission::Admin->value
                : $validated['permission'],
            'region' => $validated['region'] !== '' ? $validated['region'] : null,
        ])->save();

        // Internal workspace: the Superadmin's act of creating the account is
        // the implicit trust — mark the email verified immediately so the new
        // user is not blocked by the `verified` middleware on first sign-in.
        // No "click the verify link" flow.
        $user->forceFill(['email_verified_at' => now()])->save();

        // Send the credentials handoff email. The notification is ShouldQueue,
        // so a slow SMTP does not block this Livewire request. We catch any
        // dispatch-time failure (driver down, misconfigured) so the account is
        // still created and the Superadmin can share the password manually.
        try {
            Notification::send($user, new AccountCreatedNotification(
                user: $user,
                role: $user->role,
                permission: $user->permission ?? UserPermission::Viewer,
                region: $user->region,
                plainPassword: $validated['password'],
            ));

            session()->flash('user-created-email', 'Credentials email queued for '.$user->email.'.');
        } catch (\Throwable $exception) {
            Log::warning('Account created but credentials email could not be queued.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);
            session()->flash('user-created-email', 'Credentials email could not be queued — share the password with '.$user->email.' manually.');
        }

        $this->reset('newUser');
        $this->newUser['role'] = 'regional_manager';
        $this->newUser['permission'] = 'viewer';
        session()->flash('user-created', $user->name);
    }

    public function startEditing(int $id): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);

        $user = User::query()->findOrFail($id);
        $this->editingId = $user->id;
        $this->editing = [
            'role' => $user->role->value,
            'permission' => $user->permission?->value ?? 'viewer',
            'region' => $user->region ?? '',
            'password' => '',
        ];
    }

    public function saveEditing(): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);

        if ($this->editingId === null) {
            return;
        }

        $validated = $this->validate([
            'editing.role' => ['required', Rule::in(array_column(UserRole::cases(), 'value'))],
            'editing.permission' => ['required', Rule::in(array_column(UserPermission::cases(), 'value'))],
            'editing.region' => ['nullable', Rule::in([...ProductDashboardService::REGIONS, ''])],
            'editing.password' => ['nullable', 'string', Password::defaults()],
        ])['editing'];

        $user = User::query()->findOrFail($this->editingId);

        // Never allow the last superadmin to be demoted — that would lock
        // everyone out of /users and the manageUsers gate with no recovery
        // path in the UI.
        if ($user->role === UserRole::Superadmin
            && $validated['role'] !== UserRole::Superadmin->value
            && User::query()->where('role', UserRole::Superadmin->value)->count() <= 1) {
            $this->addError('editing.role', 'This is the only Superadmin — create another Superadmin before changing this role.');

            return;
        }

        $user->forceFill([
            'role' => $validated['role'],
            'permission' => $validated['role'] === UserRole::Superadmin->value
                ? UserPermission::Admin->value
                : $validated['permission'],
            'region' => $validated['region'] !== '' ? $validated['region'] : null,
        ])->save();

        if (filled($validated['password'] ?? null)) {
            $user->forceFill(['password' => $validated['password']])->save();
        }

        $this->reset('editingId', 'editing');
    }

    public function cancelEditing(): void
    {
        $this->reset('editingId', 'editing');
    }

    /**
     * Mark an existing user as email-verified on the spot. Used when the
     * Superadmin confirms the person out of band (e.g. in person) and there
     * is no email loop to send. The action is throttled to once per minute
     * per user so accidental double-clicks are harmless, and gated to
     * Superadmin. A no-op (flash) when the user is already verified.
     */
    public function markVerified(int $id): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);

        $user = User::query()->findOrFail($id);

        if ($user->email_verified_at !== null) {
            session()->flash('mark-verified', "{$user->name} is already verified.");

            return;
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        session()->flash('mark-verified', "{$user->name} is now verified and can sign in.");
    }

    /**
     * Resend the credentials handoff email to an existing user.
     *
     * The notification is dispatched with an empty plainPassword (we cannot
     * recover the original hashed password) — the email tells the user to use
     * the forgot-password flow if they need to set a new one. The send is
     * throttled to once every 5 minutes per recipient so a misclick cannot
     * flood someone's inbox, and a try/catch keeps an SMTP outage from
     * throwing on the Livewire request.
     */
    public function resendCredentials(int $id): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);

        $user = User::query()->findOrFail($id);

        $last = $user->credentials_resent_at;

        if ($last !== null && $last->copy()->addMinutes(5)->isFuture()) {
            $this->addError("resend-{$user->id}", "Credentials email was already sent to {$user->email} {$last->diffForHumans()}. Try again in a few minutes.");

            return;
        }

        try {
            Notification::send($user, new AccountCreatedNotification(
                user: $user,
                role: $user->role,
                permission: $user->permission ?? UserPermission::Viewer,
                region: $user->region,
                plainPassword: '',
            ));

            $user->forceFill(['credentials_resent_at' => now()])->save();
            session()->flash('resent-credentials', "Credentials email re-queued for {$user->email}.");
        } catch (\Throwable $exception) {
            Log::warning('Credentials resend failed.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);
            $this->addError("resend-{$user->id}", "Could not queue the credentials email to {$user->email} — check the mailer.");
        }
    }

    /** Regenerate a strong password without exposing it in component state. */
    public static function suggestedPassword(): string
    {
        return Str::password(16, symbols: true);
    }
}
