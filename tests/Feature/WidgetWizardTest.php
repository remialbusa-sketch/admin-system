<?php

namespace Tests\Feature;

use App\Livewire\WidgetWizard;
use App\Models\Dashboard as DashboardModel;
use App\Models\DynamicTable;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WidgetWizardTest extends TestCase
{
    use RefreshDatabase;

    private function serviceRequest(array $attributes = []): ServiceRequest
    {
        return ServiceRequest::create(array_merge([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-'.uniqid(),
            'customer_name' => 'Wizard Hospital',
        ], $attributes));
    }

    public function test_wizard_suggests_a_trend_for_a_table_with_monthly_data(): void
    {
        $this->serviceRequest(['group_status' => 'Open', 'source_updated_at' => now()]);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(WidgetWizard::class, ['table' => 'service-requests'])
            ->assertSet('tableKey', 'service-requests')
            ->assertSet('widgetType', 'line_chart')
            ->assertSet('dataset', 'by_month')
            ->assertSee('by_month', false);
    }

    public function test_the_visualize_page_renders_over_http(): void
    {
        $owner = User::factory()->superadmin()->create();
        $this->serviceRequest(['group_status' => 'Open', 'source_updated_at' => now()]);

        $this->actingAs($owner)
            ->get(route('visualize', ['table' => 'service-requests']))
            ->assertOk()
            ->assertSee('Create a data visualization');

        // The dashboard-toolbar entry point (no table preselected).
        $dashboard = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'HTTP board',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        $this->actingAs($owner)
            ->get(route('visualize', ['dashboard' => $dashboard->id]))
            ->assertOk()
            ->assertSee('Create a data visualization');

        // The dynamic-table entry point.
        $dynamic = DynamicTable::create([
            'key' => 'wizard-dynamic',
            'name' => 'Wizard dynamic',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->get(route('visualize', ['table' => $dynamic->key]))
            ->assertOk()
            ->assertSee('Create a data visualization');
    }

    public function test_create_adds_a_widget_and_connects_the_source(): void
    {
        $owner = User::factory()->superadmin()->create();

        $dashboard = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'Ops board',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);
        $dashboard->sources()->create(['table_key' => 'installed-products', 'alias' => 'pdb', 'position' => 0]);

        $this->serviceRequest(['group_status' => 'Open']);

        $component = Livewire::actingAs($owner)
            ->test(WidgetWizard::class, ['table' => 'service-requests', 'dashboard' => $dashboard->id])
            ->set('widgetType', 'donut_chart')
            ->set('dataset', 'by_group')
            ->set('title', 'Requests by group')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboards.show', $dashboard));

        $this->assertNotNull($component);

        $dashboard->refresh();

        // The table was auto-connected and the widget namespaced to its alias.
        $this->assertDatabaseHas('dashboard_sources', [
            'dashboard_id' => $dashboard->id,
            'table_key' => 'service-requests',
            'alias' => 'service_requests',
        ]);

        $widget = $dashboard->layout['widgets'][0];
        $this->assertSame('donut_chart', $widget['type']);
        $this->assertSame('service_requests.by_group', $widget['props']['dataset']);
        $this->assertSame('Requests by group', $widget['props']['label']);
        $this->assertSame('value', $widget['props']['value_key']);
    }

    public function test_create_with_a_new_dashboard_name_creates_the_dashboard(): void
    {
        // A non-superadmin with no dashboards: the wizard must offer the
        // inline "new dashboard" path (a superadmin always has the seeded
        // templates available, so this is the user this flow is for).
        $owner = User::factory()->president()->create();
        $this->serviceRequest(['group_status' => 'Completed']);

        Livewire::actingAs($owner)
            ->test(WidgetWizard::class, ['table' => 'service-requests'])
            ->set('newDashboardName', 'Service pulse')
            ->set('widgetType', 'kpi_card')
            ->set('metric', 'rows')
            ->set('title', 'Requests')
            ->call('create')
            ->assertHasNoErrors();

        $dashboard = DashboardModel::query()->where('name', 'Service pulse')->first();

        $this->assertNotNull($dashboard);
        $this->assertSame($owner->id, $dashboard->owner_id);
        $this->assertDatabaseHas('dashboard_sources', [
            'dashboard_id' => $dashboard->id,
            'table_key' => 'service-requests',
        ]);

        $generated = collect($dashboard->layout['widgets'])->last();
        $this->assertNotNull($generated);
        $this->assertSame('kpi_card', $generated['type']);
        $this->assertSame('service_requests.rows', $generated['props']['metric']);
    }

    public function test_wizard_rejects_a_dashboard_the_user_cannot_edit(): void
    {
        $owner = User::factory()->superadmin()->create();
        $other = User::factory()->president()->create();

        $dashboard = DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'Private',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        $this->actingAs($other)
            ->get(route('visualize', ['table' => 'service-requests', 'dashboard' => $dashboard->id]))
            ->assertForbidden();
    }

    public function test_selecting_create_a_new_dashboard_clears_the_dashboard(): void
    {
        $owner = User::factory()->superadmin()->create();

        DashboardModel::create([
            'owner_id' => $owner->id,
            'name' => 'Existing',
            'layout' => ['version' => 1, 'widgets' => []],
        ]);

        // The select's "-- create a new one --" option posts an empty string;
        // it must clear the selection, not throw on the typed ?int property.
        $component = Livewire::actingAs($owner)
            ->test(WidgetWizard::class, ['table' => 'service-requests'])
            ->set('dashboardId', '');

        $this->assertNull($component->get('dashboardId'));
    }

    public function test_wizard_rejects_an_unknown_table_on_create(): void
    {
        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(WidgetWizard::class, ['table' => 'not-a-table'])
            ->set('widgetType', 'kpi_card')
            ->set('metric', 'rows')
            ->set('title', 'Nope')
            ->call('create')
            ->assertHasErrors('tableKey');
    }
}
