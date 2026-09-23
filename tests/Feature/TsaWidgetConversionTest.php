<?php

namespace Tests\Feature;

use App\Livewire\TechnicalServiceAnalysis;
use App\Models\PageWidgetLayout;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TsaWidgetConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'TR-1',
            'reference_number' => 'TR-1',
            'customer_name' => 'Alpha Hospital',
            'tsp_name' => 'Alice',
            'brand' => 'SYSMEX',
            'service_status' => 'Completed',
            'service_completed_at' => now()->subDays(2),
            'repair_time_hours' => 2,
        ]);
        TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'TR-2',
            'reference_number' => 'TR-2',
            'customer_name' => 'Beta Hospital',
            'tsp_name' => null,
            'brand' => 'TERUMO',
            'service_status' => 'In-Progress',
        ]);
    }

    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    public function test_tsa_renders_widgets_by_default_with_no_curated_markup(): void
    {
        $owner = $this->superadmin();

        Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', function (array $grid): bool {
                $widgets = $grid['widgets'];

                if (count($widgets) !== 7) {
                    return false;
                }

                foreach ($widgets as $widget) {
                    if (($widget['view'] ?? null) === 'components.dashboard.widgets.error') {
                        return false;
                    }
                }

                $byId = collect($widgets)->keyBy('id');

                return ($byId['tsa-reports']['props']['metric'] ?? null) === 'reports_total'
                    && ($byId['tsa-window']['props']['metric'] ?? null) === 'window_completed'
                    && collect($widgets)->every(fn (array $widget): bool => in_array($widget['type'], ['headline_kpi', 'supporting_kpi'], true));
            })
            ->assertDontSee('Headline metrics');
    }

    public function test_tsa_widgets_are_fully_customizable(): void
    {
        $owner = $this->superadmin();

        $component = Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa');

        $component
            ->call('editWidgetFor', 'tsa', 'tsa-reports')
            ->set('widgetGrids.tsa.settingsProps.label', 'My reports')
            ->call('applyWidgetSettingsFor', 'tsa')
            ->assertSet('widgetGrids.tsa.settingsError', null);

        $this->assertSame(
            'My reports',
            collect($component->get('widgetGrids')['tsa']['draftLayout']['widgets'])->firstWhere('id', 'tsa-reports')['props']['label'],
        );

        $component->call('saveWidgetGrid', [], 'tsa')->assertHasNoErrors();

        $this->assertTrue(PageWidgetLayout::query()->where('user_id', $owner->id)->where('page', 'tsa')->exists());
    }

    public function test_tsa_reset_restores_the_shipped_cards(): void
    {
        $owner = $this->superadmin();

        Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->call('toggleCustomizingFor', 'tsa')
            ->call('saveWidgetGrid', [], 'tsa')
            ->assertHasNoErrors();

        Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->call('resetWidgetGrid', 'tsa')
            ->assertHasNoErrors();

        $this->assertFalse(PageWidgetLayout::query()->where('user_id', $owner->id)->where('page', 'tsa')->exists());

        Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->assertViewHas('tsaGrid', fn (array $grid): bool => count($grid['widgets']) === 7);
    }

    public function test_tsa_customizing_requires_edit_access(): void
    {
        $viewer = User::factory()->president()->create();

        Livewire::actingAs($viewer)
            ->test(TechnicalServiceAnalysis::class)
            ->assertDontSee('Customize grid')
            ->call('toggleCustomizingFor', 'tsa')
            ->assertForbidden();
    }
}
