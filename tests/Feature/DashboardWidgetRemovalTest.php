<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Dashboard as DashboardModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_widget_can_be_deleted_leaving_an_empty_dashboard(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'Single widget',
            'layout' => [
                'version' => 1,
                'widgets' => [[
                    'id' => 'kpi-1',
                    'type' => 'kpi_card',
                    'w' => 4,
                    'h' => 2,
                    'props' => ['label' => 'Only widget', 'metric' => 'installed'],
                ]],
            ],
        ]);

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->call('toggleCustomizing');

        // The client sends an empty geometry once the last widget is gone.
        $component->call('syncLayout', []);

        $this->assertSame([], $component->get('draftLayout')['widgets']);

        $component->call('saveLayout', [])->assertHasNoErrors();

        $this->assertSame([], $dashboard->fresh()->layout['widgets']);

        // The empty grid renders its empty state, not an error.
        Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->assertSee('No widgets on this dashboard yet');
    }

    public function test_prebuilt_config_widget_is_fully_customizable(): void
    {
        $owner = User::factory()->superadmin()->create();

        // Home renders the shipped default layout (prebuilt widgets).
        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->call('toggleCustomizing');

        $widgets = $component->get('draftLayout')['widgets'];

        $this->assertNotEmpty($widgets, 'Expected prebuilt widgets from the default layout.');

        $widgetId = $widgets[0]['id'];

        $component
            ->call('editWidget', $widgetId)
            ->set('settingsProps.label', 'My renamed widget')
            ->call('applyWidgetSettings')
            ->assertSet('settingsError', null);

        $this->assertSame(
            'My renamed widget',
            collect($component->get('draftLayout')['widgets'])->firstWhere('id', $widgetId)['props']['label'],
        );
    }
}
