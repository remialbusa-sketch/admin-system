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

    public function test_conversion_seeds_customizable_headline_widgets(): void
    {
        $owner = $this->superadmin();

        $component = Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->assertSee('Convert to widgets')
            ->call('convertHeadlinesToWidgets')
            ->assertHasNoErrors();

        $widgets = $component->get('widgetGrids')['tsa']['draftLayout']['widgets'];

        $this->assertCount(7, $widgets);
        $this->assertSame(
            ['reports_total', 'completed', 'assigned_tsp', 'avg_repair_hours', 'avg_response_hours', 'window_completed', 'unassigned'],
            array_column(array_column($widgets, 'props'), 'metric'),
        );

        $component
            ->call('editWidgetFor', 'tsa', $widgets[0]['id'])
            ->set('widgetGrids.tsa.settingsProps.label', 'My reports')
            ->call('applyWidgetSettingsFor', 'tsa')
            ->assertSet('widgetGrids.tsa.settingsError', null);

        $this->assertSame(
            'My reports',
            collect($component->get('widgetGrids')['tsa']['draftLayout']['widgets'])->firstWhere('id', $widgets[0]['id'])['props']['label'],
        );
    }

    public function test_curated_cards_hide_once_converted_and_reset_restores_them(): void
    {
        $owner = $this->superadmin();

        Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->assertSee('reports on file');

        $component = Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->call('convertHeadlinesToWidgets');

        $component->call('saveWidgetGrid', [], 'tsa')->assertHasNoErrors();

        $this->assertTrue(PageWidgetLayout::query()->where('user_id', $owner->id)->where('page', 'tsa')->exists());

        Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->assertDontSee('reports on file')
            ->assertDontSee('Convert to widgets');

        Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->call('resetWidgetGrid', 'tsa')
            ->assertHasNoErrors();

        $this->assertFalse(PageWidgetLayout::query()->where('user_id', $owner->id)->where('page', 'tsa')->exists());

        Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->assertSee('reports on file');
    }

    public function test_conversion_requires_edit_access_and_is_single_shot(): void
    {
        $viewer = User::factory()->president()->create();

        Livewire::actingAs($viewer)
            ->test(TechnicalServiceAnalysis::class)
            ->assertDontSee('Convert to widgets')
            ->call('convertHeadlinesToWidgets')
            ->assertForbidden();

        $owner = $this->superadmin();

        PageWidgetLayout::create(['user_id' => $owner->id, 'page' => 'tsa', 'layout' => ['version' => 1, 'widgets' => []]]);

        $component = Livewire::actingAs($owner)
            ->test(TechnicalServiceAnalysis::class)
            ->call('convertHeadlinesToWidgets')
            ->assertHasNoErrors();

        $this->assertFalse($component->get('widgetGrids')['tsa']['customizing'] ?? false);
    }
}
