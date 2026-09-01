<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\UserManagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_superadmin_creates_account_with_role_and_region(): void
    {
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(UserManagement::class)
            ->set('newUser.name', 'New Manager')
            ->set('newUser.email', 'manager@example.com')
            ->set('newUser.password', 'Sup3r-Secret!')
            ->set('newUser.role', 'regional_manager')
            ->set('newUser.region', 'Visayas')
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'manager@example.com')->firstOrFail();
        $this->assertSame(UserRole::RegionalManager, $user->role);
        $this->assertSame('Visayas', $user->region);
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
}
