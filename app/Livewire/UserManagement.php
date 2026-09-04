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

    /** Regenerate a strong password without exposing it in component state. */
    public static function suggestedPassword(): string
    {
        return Str::password(16, symbols: true);
    }
}
