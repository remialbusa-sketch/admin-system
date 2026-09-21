<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Models\Dashboard;
use App\Models\DashboardShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SidebarDashboardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_lists_the_dashboards_link_and_the_users_dashboards(): void
    {
        $user = User::factory()->superadmin()->create();
        $other = User::factory()->president()->create();

        $owned = Dashboard::create([
            'owner_id' => $user->id,
            'name' => 'My operations board',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        $shared = Dashboard::create([
            'owner_id' => $other->id,
            'name' => 'Shared leadership board',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        DashboardShare::create([
            'dashboard_id' => $shared->id,
            'user_id' => $user->id,
            'permission' => 'view',
            'shared_by' => $other->id,
        ]);

        // Someone else's private dashboard must not appear.
        Dashboard::create([
            'owner_id' => $other->id,
            'name' => 'Private board',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->assertSee('Dashboards')
            ->assertSee('My operations board')
            ->assertSee('Shared leadership board')
            ->assertDontSee('Private board');

        // A newly created dashboard appears after the list-updated event.
        $fresh = Dashboard::create([
            'owner_id' => $user->id,
            'name' => 'Brand new board',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->dispatch('dashboard-list-updated')
            ->assertSee('Brand new board');

        $this->assertNotNull($owned);
        $this->assertNotNull($fresh);
    }
}
