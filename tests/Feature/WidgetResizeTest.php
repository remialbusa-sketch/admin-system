<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\TechnicalServiceAnalysis;
use App\Models\User;
use App\Support\SystemDashboards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Widget resizing is three-directional: the right edge resizes width, the
 * bottom edge resizes height, and the corner resizes both at once. Geometry
 * snaps to grid units and persists through Done like every other edit.
 */
class WidgetResizeTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    public function test_edit_mode_renders_all_three_resize_handles(): void
    {
        $component = Livewire::actingAs($this->superadmin())->test(Dashboard::class);

        // At rest there are no resize affordances.
        $component
            ->assertDontSee('data-widget-resize="x"', false)
            ->assertDontSee('data-widget-resize="y"', false)
            ->assertDontSee('data-widget-resize="xy"', false);

        $component
            ->call('toggleCustomizing')
            ->assertSee('data-widget-resize="x"', false)
            ->assertSee('data-widget-resize="y"', false)
            ->assertSee('data-widget-resize="xy"', false);
    }

    public function test_resized_height_survives_save(): void
    {
        Livewire::actingAs($this->superadmin())
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->call('saveWidgetGrid', [
                ['id' => 'tsa-reports', 'w' => 6, 'h' => 4],
            ], 'tsa')
            ->assertHasNoErrors();

        $widgets = SystemDashboards::coreRow('Technical Service Analysis')->layout['widgets'] ?? [];
        $resized = collect($widgets)->firstWhere('id', 'tsa-reports');

        $this->assertNotNull($resized);
        $this->assertSame(6, $resized['w']);
        $this->assertSame(4, $resized['h']);
    }
}
