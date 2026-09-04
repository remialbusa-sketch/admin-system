<?php

namespace Tests\Feature;

use App\Enums\UserPermission;
use App\Enums\UserRole;
use App\Livewire\UserManagement;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_open_user_management(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/users')
            ->assertOk();
    }

    public function test_non_superadmin_cannot_open_user_management(): void
    {
        $this->actingAs(User::factory()->president()->create())
            ->get('/users')
            ->assertForbidden();
    }

    public function test_superadmin_creates_account_with_role_region_and_permission(): void
    {
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(UserManagement::class)
            ->set('newUser.name', 'New Coordinator')
            ->set('newUser.email', 'coordinator@example.com')
            ->set('newUser.password', 'Sup3r-Secret!')
            ->set('newUser.role', 'service_coordinator')
            ->set('newUser.permission', 'editor')
            ->set('newUser.region', 'Visayas')
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'coordinator@example.com')->firstOrFail();
        $this->assertSame(UserRole::ServiceCoordinator, $user->role);
        $this->assertSame(UserPermission::Editor, $user->permission);
        $this->assertSame('Visayas', $user->region);
    }

    public function test_superadmin_role_forces_admin_permission(): void
    {
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(UserManagement::class)
            ->set('newUser.name', 'Guard')
            ->set('newUser.email', 'guard@example.com')
            ->set('newUser.password', 'Sup3r-Secret!')
            ->set('newUser.role', 'superadmin')
            ->set('newUser.permission', 'viewer') // must be overridden
            ->call('createUser');

        $this->assertSame(UserPermission::Admin, User::query()->where('email', 'guard@example.com')->value('permission'));
    }

    public function test_created_account_defaults_to_viewer(): void
    {
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(UserManagement::class)
            ->set('newUser.name', 'Jo')
            ->set('newUser.email', 'jo@example.com')
            ->set('newUser.password', 'Sup3r-Secret!')
            ->set('newUser.role', 'assistant')
            ->call('createUser');

        $this->assertSame(UserPermission::Viewer, User::query()->where('email', 'jo@example.com')->value('permission'));
    }

    public function test_new_roles_and_permission_levels_are_available(): void
    {
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(UserManagement::class)
            ->assertSee('Service Coordinator')
            ->assertSee('Assistant Coordinator')
            ->assertSee('Assistant')
            ->assertSee('Read-only — dashboards, tables, exports.')
            ->assertSee('Read + edit records in the grid.')
            ->assertSee('Read + edit + import workbooks.');
    }

    public function test_superadmin_can_change_permission_level(): void
    {
        $target = User::factory()->serviceCoordinator()->create(['permission' => UserPermission::Viewer]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(UserManagement::class)
            ->call('startEditing', $target->id)
            ->set('editing.permission', 'editor')
            ->set('editing.role', 'service_coordinator')
            ->call('saveEditing')
            ->assertHasNoErrors();

        $this->assertSame(UserPermission::Editor, $target->refresh()->permission);
    }

    public function test_superadmin_can_change_role_and_reset_password(): void
    {
        $target = User::factory()->regionalManager('NCR')->create();

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(UserManagement::class)
            ->call('startEditing', $target->id)
            ->set('editing.role', 'president')
            ->set('editing.region', '')
            ->set('editing.password', 'Another-Secret!')
            ->call('saveEditing')
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertSame(UserRole::President, $target->role);
        $this->assertNull($target->region);
        $this->assertTrue(auth()->validate(['email' => $target->email, 'password' => 'Another-Secret!']));
    }

    public function test_created_accounts_appear_in_the_listing(): void
    {
        $creator = User::factory()->superadmin()->create();
        User::factory()->regionalManager('Mindanao')->create(['name' => 'Listed Manager']);

        Livewire::actingAs($creator)
            ->test(UserManagement::class)
            ->assertSee('Listed Manager')
            ->assertSee('Mindanao');
    }

    public function test_president_cannot_open_user_management(): void
    {
        $this->actingAs(User::factory()->president()->create())
            ->get('/users')
            ->assertForbidden();
    }

    public function test_created_account_is_marked_verified_immediately(): void
    {
        // Internal workspace: a Superadmin-created account is trusted; the new
        // user must be able to sign in without a verify-email round-trip.
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(UserManagement::class)
            ->set('newUser.name', 'Trusted New Hire')
            ->set('newUser.email', 'newhire@example.com')
            ->set('newUser.password', 'Sup3r-Secret!')
            ->set('newUser.role', 'service_coordinator')
            ->set('newUser.permission', 'editor')
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'newhire@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at, 'admin-created accounts must be verified immediately so the user can sign in.');
    }

    public function test_created_account_dispatches_credentials_notification(): void
    {
        Notification::fake();

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(UserManagement::class)
            ->set('newUser.name', 'Notified Hire')
            ->set('newUser.email', 'notified@example.com')
            ->set('newUser.password', 'Sup3r-Secret!')
            ->set('newUser.role', 'service_coordinator')
            ->set('newUser.permission', 'editor')
            ->set('newUser.region', 'Visayas')
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'notified@example.com')->firstOrFail();
        $captured = null;

        Notification::assertSentTo(
            $user,
            AccountCreatedNotification::class,
            function (AccountCreatedNotification $notification) use (&$captured): bool {
                $captured = $notification;

                return true;
            }
        );

        $this->assertNotNull($captured, 'the AccountCreatedNotification should have been dispatched.');
        $this->assertSame(UserRole::ServiceCoordinator, $captured->role);
        $this->assertSame(UserPermission::Editor, $captured->permission);
        $this->assertSame('Visayas', $captured->region);
        $this->assertSame('Sup3r-Secret!', $captured->plainPassword);
    }

    public function test_user_still_created_when_notification_dispatch_fails(): void
    {
        // SMTP down / mailer misconfigured must NOT prevent the account from
        // being created. The Superadmin can hand the password out of band.
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP unreachable.'));

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(UserManagement::class)
            ->set('newUser.name', 'Mailer Down')
            ->set('newUser.email', 'mailer-down@example.com')
            ->set('newUser.password', 'Sup3r-Secret!')
            ->set('newUser.role', 'assistant')
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'mailer-down@example.com')->firstOrFail();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(UserRole::Assistant, $user->role);
    }
}
