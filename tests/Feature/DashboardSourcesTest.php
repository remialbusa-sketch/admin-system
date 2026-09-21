<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Dashboard as DashboardModel;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardSourcesTest extends TestCase
{
    use RefreshDatabase;

    private function dashboardFor(User $owner): DashboardModel
    {
        $dashboard = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'Sources board',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        $dashboard->sources()->create(['table_key' => 'installed-products', 'alias' => 'pdb', 'position' => 0]);

        return $dashboard;
    }

    public function test_connected_sources_offer_namespaced_datasets_and_validate(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->dashboardFor($owner);

        $dashboard->sources()->create(['table_key' => 'service-requests', 'alias' => 'sr', 'position' => 1]);

        ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-src-1',
            'group_status' => 'Open',
            'region' => 'Region 6',
        ]);

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->assertViewHas('datasetOptions', fn (array $options): bool => array_key_exists('sr.by_group', $options))
            ->call('toggleCustomizing')
            ->call('addWidget', 'bar_chart')
            ->assertHasNoErrors();

        $widgetId = $component->get('draftLayout')['widgets'][0]['id'];

        // A namespaced dataset passes validation and lands in the draft props.
        $component
            ->call('editWidget', $widgetId)
            ->set('settingsProps.label', 'Requests by group')
            ->set('settingsProps.dataset', 'sr.by_group')
            ->call('applyWidgetSettings')
            ->assertSet('settingsError', null);

        $this->assertSame(
            'sr.by_group',
            $component->get('draftLayout')['widgets'][0]['props']['dataset'],
        );

        // An unknown dataset is rejected with a settings error.
        $component
            ->call('editWidget', $widgetId)
            ->set('settingsProps.dataset', 'sr.not_a_dataset')
            ->call('applyWidgetSettings')
            ->assertSet('settingsError', 'Dataset is not a known dataset.');
    }

    public function test_a_widget_reads_data_from_a_connected_source_after_saving(): void
    {
        $owner = User::factory()->superadmin()->create();
        $dashboard = $this->dashboardFor($owner);

        $dashboard->sources()->create(['table_key' => 'service-requests', 'alias' => 'sr', 'position' => 1]);

        ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-src-2',
            'group_status' => 'Open',
            'region' => 'Region 6',
        ]);
        ServiceRequest::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-src-3',
            'group_status' => 'Open',
            'region' => 'Region 6',
        ]);

        $dashboard->update([
            'layout' => [
                'version' => 1,
                'widgets' => [[
                    'id' => 'bar-1',
                    'type' => 'bar_chart',
                    'w' => 6,
                    'h' => 3,
                    'props' => ['label' => 'Requests by group', 'dataset' => 'sr.by_group'],
                ]],
            ],
        ]);

        $component = Livewire::actingAs($owner)
            ->test(Dashboard::class, ['dashboard' => $dashboard])
            ->assertViewHas('grid', function (array $grid): bool {
                $items = $grid['widgets'][0]['data']['items'] ?? [];

                return count($items) === 1
                    && $items[0]['label'] === 'Open'
                    && $items[0]['value'] === 2.0;
            });

        $this->assertNotNull($component);
    }
}
