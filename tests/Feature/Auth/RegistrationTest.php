<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\MassAssignmentException;
use Livewire\Volt\Volt;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_disabled_by_default(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_screen_can_be_rendered_when_enabled(): void
    {
        // The route only exists when ALLOW_REGISTRATION was true at boot, so
        // register it exactly the way routes/auth.php does and exercise the
        // component itself.
        Volt::route('register', 'pages.auth.register')->name('register');

        $this->get('/register')
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
    }

    public function test_new_users_can_register_when_enabled(): void
    {
        config(['features.allow_registration' => true]);

        $component = Volt::test('pages.auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_role_is_not_mass_assignable(): void
    {
        // Regression for the audit finding: registration/profile flows must
        // never be able to mint a privileged account via mass assignment.
        // (Factories bypass guards by design; direct create() must not.)
        try {
            $user = User::create([
                'name' => 'Escalation Attempt',
                'email' => 'escalation@example.com',
                'password' => 'password',
                'role' => UserRole::Superadmin->value,
                'region' => 'NCR',
            ]);

            $this->assertNotSame('superadmin', $user->fresh()->role->value);
        } catch (MassAssignmentException) {
            $this->addToAssertionCount(1); // strict mode blocked it loudly
        }
    }
}
