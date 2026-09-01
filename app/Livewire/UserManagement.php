<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ProductDashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * Superadmin-only account administration: create accounts, assign roles and
 * regions, and reset passwords. Roles are applied with forceFill — they are
 * deliberately not mass-assignable anywhere else in the app.
 */
class UserManagement extends Component
{
    public array $newUser = [
        'name' => '',
        'email' => '',
        'password' => '',
        'role' => 'regional_manager',
        'region' => '',
    ];

    public ?int $editingId = null;

    public array $editing = [
        'role' => 'regional_manager',
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
            'newUser.region' => ['nullable', Rule::in([...ProductDashboardService::REGIONS, ''])],
        ])['newUser'];

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        $user->forceFill([
            'role' => $validated['role'],
            'region' => $validated['region'] !== '' ? $validated['region'] : null,
        ])->save();

        $this->reset('newUser');
        $this->newUser['role'] = 'regional_manager';
        session()->flash('user-created', $user->name);
    }

    public function startEditing(int $id): void
    {
        abort_unless(auth()->user()?->role === UserRole::Superadmin, 403);

        $user = User::query()->findOrFail($id);
        $this->editingId = $user->id;
        $this->editing = [
            'role' => $user->role->value,
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
            'editing.region' => ['nullable', Rule::in([...ProductDashboardService::REGIONS, ''])],
            'editing.password' => ['nullable', 'string', Password::defaults()],
        ])['editing'];

        $user = User::query()->findOrFail($this->editingId);
        $user->forceFill([
            'role' => $validated['role'],
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
