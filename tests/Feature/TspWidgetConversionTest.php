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

    public function test_conversion_seeds_customizable_kpi_widgets(): void
    {
        $owner = $this->superadmin();

        $component = Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->assertSee('Convert to widgets')
            ->call('convertHeadlinesToWidgets')
            ->assertHasNoErrors();

        $widgets = $component->get('widgetGrids')['tsp']['draftLayout']['widgets'];

        $this->assertCount(4, $widgets);
        $this->assertSame(
            ['active_tsps', 'open_records', 'resolution_rate', 'total_reports'],
            array_column(array_column($widgets, 'props'), 'metric'),
        );
        $this->assertSame('%', $widgets[2]['props']['suffix']);

        $component
            ->call('editWidgetFor', 'tsp', $widgets[0]['id'])
            ->set('widgetGrids.tsp.settingsProps.label', 'My TSPs')
            ->call('applyWidgetSettingsFor', 'tsp')
            ->assertSet('widgetGrids.tsp.settingsError', null);

        $this->assertSame(
            'My TSPs',
            collect($component->get('widgetGrids')['tsp']['draftLayout']['widgets'])->firstWhere('id', $widgets[0]['id'])['props']['label'],
        );
    }

    public function test_curated_strip_hides_once_converted_and_reset_restores_it(): void
    {
        $owner = $this->superadmin();

        Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->assertSee('TSP KPI summary');

        $component = Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->call('convertHeadlinesToWidgets');

        $component->call('saveWidgetGrid', [], 'tsp')->assertHasNoErrors();

        $this->assertTrue(PageWidgetLayout::query()->where('user_id', $owner->id)->where('page', 'tsp')->exists());

        Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->assertDontSee('TSP KPI summary')
            ->assertDontSee('Convert to widgets');

        Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->call('resetWidgetGrid', 'tsp')
            ->assertHasNoErrors();

        Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->assertSee('TSP KPI summary');
    }

    public function test_conversion_requires_edit_access_and_is_single_shot(): void
    {
        $viewer = User::factory()->president()->create();

        Livewire::actingAs($viewer)
            ->test(TspAnalytics::class)
            ->assertDontSee('Convert to widgets')
            ->call('convertHeadlinesToWidgets')
            ->assertForbidden();

        $owner = $this->superadmin();

        PageWidgetLayout::create(['user_id' => $owner->id, 'page' => 'tsp', 'layout' => ['version' => 1, 'widgets' => []]]);

        $component = Livewire::actingAs($owner)
            ->test(TspAnalytics::class)
            ->call('convertHeadlinesToWidgets')
            ->assertHasNoErrors();

        $this->assertFalse($component->get('widgetGrids')['tsp']['customizing'] ?? false);
    }
}
