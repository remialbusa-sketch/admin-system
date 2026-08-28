<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagedTablePagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_managed_table_pages_render_with_the_dashboard_layout(): void
    {
        $user = User::factory()->superadmin()->create();

        foreach (['tables', 'installed-products', 'service-requests', 'technical-reports', 'history-reports', 'personnel', 'technical-service-analysis'] as $route) {
            $this->actingAs($user)
                ->get("/{$route}")
                ->assertOk()
                ->assertSee('Admin System');
        }
    }
}
