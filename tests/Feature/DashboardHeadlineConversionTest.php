<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\DashboardLayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardHeadlineConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversion_seeds_customizable_headline_widgets(): void
    {
        $owner = User::factory()->superadmin()->create();

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->call('convertHeadlinesToWidgets')
            ->assertHasNoErrors()
            ->assertSet('customizing', true);

        $widgets = $component->get('draftLayout')['widgets'];

        $this->assertCount(5, $widgets);
        $this->assertSame(
            ['installed', 'active', 'warranty_covered', 'contracts', ''],
            array_column(array_column($widgets, 'props'), 'metric'),
        );
        $this->assertSame('round(annual_bu_charges / 1000000, 1)', $widgets[4]['props']['formula']);

        // Every converted widget opens in settings and accepts edits.
        $component
            ->call('editWidget', $widgets[0]['id'])
            ->set('settingsProps.label', 'My installs')
            ->call('applyWidgetSettings')
            ->assertSet('settingsError', null);

        $this->assertSame(
            'My installs',
            collect($component->get('draftLayout')['widgets'])->firstWhere('id', $widgets[0]['id'])['props']['label'],
        );
    }

    public function test_curated_cards_hide_once_a_personal_layout_exists(): void
    {
        $owner = User::factory()->superadmin()->create();

        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->assertSee('units installed');

        DashboardLayout::create(['user_id' => $owner->id, 'layout' => ['version' => 1, 'widgets' => []]]);

        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->assertDontSee('units installed');
    }

    public function test_conversion_is_superadmin_only_and_single_shot(): void
    {
        $viewer = User::factory()->president()->create();

        Livewire::actingAs($viewer)
            ->test(Dashboard::class)
            ->call('convertHeadlinesToWidgets')
            ->assertForbidden();

        $owner = User::factory()->superadmin()->create();

        DashboardLayout::create(['user_id' => $owner->id, 'layout' => ['version' => 1, 'widgets' => []]]);

        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->call('convertHeadlinesToWidgets')
            ->assertSet('customizing', false)
            ->assertSet('draftLayout', []);
    }

    public function test_reset_restores_the_curated_cards(): void
    {
        $owner = User::factory()->superadmin()->create();

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->call('convertHeadlinesToWidgets');

        $component->call('saveLayout', [])->assertHasNoErrors();

        $this->assertTrue(DashboardLayout::query()->where('user_id', $owner->id)->exists());

        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->call('resetLayout')
            ->assertHasNoErrors();

        $this->assertFalse(DashboardLayout::query()->where('user_id', $owner->id)->exists());

        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->assertSee('units installed');
    }
}
