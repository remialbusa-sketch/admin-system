<?php

namespace Tests\Feature;

use App\Livewire\TspAnalytics;
use App\Models\PageWidgetLayout;
use App\Models\TechnicalPersonnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TspWidgetConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TechnicalPersonnel::create([
            'source_system' => 'personnel_list',
            'source_record_id' => 'personnel-tsp-1',
            'name' => 'Field Person',
            'position' => 'Field Service Engineer',
            'branch' => 'NCR',
            'region' => 'NCR',
        ]);
    }

    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    public function test_tsp_renders_widgets_by_default_with_no_curated_markup(): void
    {
        $owner = $this->superadmin();

        Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->assertViewHas('tspGrid', function (array $grid): bool {
                $widgets = $grid['widgets'];

                if (count($widgets) !== 4) {
                    return false;
                }

                foreach ($widgets as $widget) {
                    if (($widget['view'] ?? null) === 'components.dashboard.widgets.error') {
                        return false;
                    }
                }

                $byId = collect($widgets)->keyBy('id');

                return ($byId['tsp-completion']['props']['metric'] ?? null) === 'completion_rate'
                    && ($byId['tsp-completion']['props']['suffix'] ?? null) === '%'
                    && ($byId['tsp-filtered']['props']['metric'] ?? null) === 'filtered_reports'
                    && collect($widgets)->every(fn (array $widget): bool => ($widget['type'] ?? null) === 'supporting_kpi');
            })
            ->assertDontSee('TSP KPI summary');
    }

    public function test_tsp_widgets_are_fully_customizable(): void
    {
        $owner = $this->superadmin();

        $component = Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->call('toggleCustomizingFor', 'tsp');

        $component
            ->call('editWidgetFor', 'tsp', 'tsp-filtered')
            ->set('widgetGrids.tsp.settingsProps.label', 'My filtered')
            ->call('applyWidgetSettingsFor', 'tsp')
            ->assertSet('widgetGrids.tsp.settingsError', null);

        $this->assertSame(
            'My filtered',
            collect($component->get('widgetGrids')['tsp']['draftLayout']['widgets'])->firstWhere('id', 'tsp-filtered')['props']['label'],
        );

        $component->call('saveWidgetGrid', [], 'tsp')->assertHasNoErrors();

        $this->assertTrue(PageWidgetLayout::query()->where('user_id', $owner->id)->where('page', 'tsp')->exists());
    }

    public function test_tsp_reset_restores_the_shipped_cards(): void
    {
        $owner = $this->superadmin();

        Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->call('toggleCustomizingFor', 'tsp')
            ->call('saveWidgetGrid', [], 'tsp')
            ->assertHasNoErrors();

        Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->call('resetWidgetGrid', 'tsp')
            ->assertHasNoErrors();

        Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->assertViewHas('tspGrid', fn (array $grid): bool => count($grid['widgets']) === 4);
    }

    public function test_tsp_customizing_requires_edit_access(): void
    {
        $viewer = User::factory()->president()->create();

        Livewire::actingAs($viewer)
            ->test(TspAnalytics::class)
            ->assertDontSee('Customize grid')
            ->call('toggleCustomizingFor', 'tsp')
            ->assertForbidden();
    }
}
