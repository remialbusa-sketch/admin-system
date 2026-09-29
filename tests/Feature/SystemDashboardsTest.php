<?php

namespace Tests\Feature;

use App\Models\Dashboard as DashboardModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemDashboardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_core_dashboards_are_seeded(): void
    {
        $systems = DashboardModel::query()->where('is_system', true)->orderBy('name')->get();

        $this->assertSame(
            ['Home', 'TSP Analytics', 'Technical Service Analysis'],
            $systems->pluck('name')->sort()->values()->all(),
        );

        foreach ($systems as $system) {
            $this->assertNull($system->owner_id);
            $this->assertNotEmpty($system->layout['widgets'] ?? []);
        }

        // Home speaks the bare Product Database vocabulary (no sources — a
        // source would shrink widget options to `pdb.*` only).
        $this->assertSame(0, $systems->firstWhere('name', 'Home')->sources()->count());

        $tsa = $systems->firstWhere('name', 'Technical Service Analysis');
        $this->assertSame('tr', $tsa->sources()->first()->alias);
        $this->assertSame('technical-reports', $tsa->sources()->first()->table_key);

        // The rows carry the pages' widget layouts (bare metric keys — the
        // pages are their only renderer).
        $this->assertCount(7, $tsa->layout['widgets']);
        $this->assertSame(
            'reports_total',
            collect($tsa->layout['widgets'])->firstWhere('id', 'tsa-reports')['props']['metric'] ?? null,
        );
        $this->assertCount(4, $systems->firstWhere('name', 'TSP Analytics')->layout['widgets']);
    }

    public function test_opening_a_core_row_redirects_to_its_page_without_creating_a_copy(): void
    {
        $user = User::factory()->president()->create();
        $system = DashboardModel::query()->where('is_system', true)->where('name', 'Technical Service Analysis')->firstOrFail();

        // No share required: core rows are viewable by everyone.
        $this->actingAs($user)
            ->get(route('dashboards.show', $system))
            ->assertRedirect(route('technical-service-analysis'));

        // The personal-copy flow is gone: opening never creates a row.
        $this->assertSame(0, DashboardModel::query()->where('owner_id', $user->id)->count());
    }

    public function test_a_superadmin_opening_a_core_row_lands_on_the_same_page(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $system = DashboardModel::query()->where('is_system', true)->where('name', 'TSP Analytics')->firstOrFail();

        $this->actingAs($superadmin)
            ->get(route('dashboards.show', $system))
            ->assertRedirect(route('tsp-analytics'));

        // No copy for anyone — editors edit the shared row's layout from
        // the page itself.
        $this->assertSame(0, DashboardModel::query()->where('owner_id', $superadmin->id)->count());
    }
}
