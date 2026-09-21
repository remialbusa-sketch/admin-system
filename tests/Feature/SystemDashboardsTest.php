<?php

namespace Tests\Feature;

use App\Models\Dashboard as DashboardModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemDashboardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_curated_dashboards_are_seeded(): void
    {
        $systems = DashboardModel::query()->where('is_system', true)->orderBy('name')->get();

        $this->assertSame(
            ['Home', 'TSP Analytics', 'Technical Service Analysis'],
            $systems->pluck('name')->sort()->values()->all(),
        );

        foreach ($systems as $system) {
            $this->assertNull($system->owner_id);
            $this->assertNotEmpty($system->layout['widgets'] ?? []);
            $this->assertGreaterThan(0, $system->sources()->count());
        }

        $tsa = $systems->firstWhere('name', 'Technical Service Analysis');
        $this->assertSame('tr', $tsa->sources()->first()->alias);
        $this->assertSame('technical-reports', $tsa->sources()->first()->table_key);
    }

    public function test_opening_a_template_creates_one_editable_copy_and_reuses_it(): void
    {
        $user = User::factory()->president()->create();
        $system = DashboardModel::query()->where('is_system', true)->where('name', 'Technical Service Analysis')->firstOrFail();

        $this->actingAs($user)->get(route('dashboards.show', $system))->assertRedirect();

        $copy = DashboardModel::query()
            ->where('owner_id', $user->id)
            ->where('name', 'Technical Service Analysis')
            ->first();

        $this->assertNotNull($copy);
        $this->assertCount($system->sources()->count(), $copy->sources()->get());
        $this->assertCount(count($system->layout['widgets']), $copy->layout['widgets']);

        // Re-opening the template returns the same copy (no duplicates).
        $this->actingAs($user)->get(route('dashboards.show', $system))->assertRedirect(route('dashboards.show', $copy));

        $this->assertSame(
            1,
            DashboardModel::query()->where('owner_id', $user->id)->where('name', 'Technical Service Analysis')->count(),
        );
    }

    public function test_a_copy_renders_live_widget_data_from_its_sources(): void
    {
        // A regular user gets the personal copy (superadmins curate the
        // template directly — see DashboardSuperadminTest).
        $user = User::factory()->president()->create();
        $system = DashboardModel::query()->where('is_system', true)->where('name', 'TSP Analytics')->firstOrFail();

        $this->actingAs($user)->get(route('dashboards.show', $system));

        $copy = DashboardModel::query()
            ->where('owner_id', $user->id)
            ->where('name', 'TSP Analytics')
            ->firstOrFail();

        // The seeded widgets must resolve through the real engine (no error
        // cards) even with empty source tables.
        $this->actingAs($user)
            ->get(route('dashboards.show', $copy))
            ->assertOk()
            ->assertSee('Requests by region')
            ->assertSee('Personnel by position');
    }

    public function test_a_superadmin_edits_the_template_directly_without_a_copy(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $system = DashboardModel::query()->where('is_system', true)->where('name', 'TSP Analytics')->firstOrFail();

        $this->actingAs($superadmin)
            ->get(route('dashboards.show', $system))
            ->assertOk()
            ->assertSee('Requests by region');

        // No personal copy is created for a superadmin.
        $this->assertSame(
            0,
            DashboardModel::query()->where('owner_id', $superadmin->id)->where('name', 'TSP Analytics')->count(),
        );
    }
}
