<?php

namespace Tests\Feature;

use App\Livewire\TechnicalReportTable;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The fixed columns the operator asked to be pick-lists (Ticket Status,
 * Customer Name, Brand, TSP ID) must declare type `select` with options
 * computed from the live data, reach the grid payload, and — the trap that
 * silently blocks edits — have a matching rule in rules() (updateField
 * aborts 422 for any field missing from that list).
 */
class TechnicalReportSelectColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function report(string $suffix, array $extra = []): TechnicalReport
    {
        return TechnicalReport::create(array_merge([
            'source_system' => 'executive',
            'source_record_id' => 'REF-'.$suffix,
            'reference_number' => 'REF-'.$suffix,
        ], $extra));
    }

    public function test_pick_list_columns_declare_select_with_options_from_the_data(): void
    {
        $this->report('1', [
            'customer_name' => 'Alpha Hospital',
            'ticket_status' => 'Open',
            'brand' => 'Canon',
            'tsp_name' => 'TSP-01',
        ]);
        $this->report('2', [
            'customer_name' => 'Beta Clinic',
            'ticket_status' => 'Closed',
            'brand' => 'Canon',
            'tsp_name' => 'TSP-01',
        ]);

        $columns = collect(app(TechnicalReportTable::class)->columns())->keyBy('key');

        foreach (['customer_name', 'ticket_status', 'brand', 'tsp_name'] as $key) {
            $this->assertSame('select', $columns[$key]['type'], $key.' must be a pick-list.');
            $this->assertNotEmpty($columns[$key]['options'] ?? [], $key.' options come from the data.');
        }

        // Distinct, ordered, deduplicated — and blanks never become choices.
        $this->assertSame(['Alpha Hospital', 'Beta Clinic'], $columns['customer_name']['options']);
        $this->assertSame(['Canon'], $columns['brand']['options']);
        $this->assertSame(['Closed', 'Open'], $columns['ticket_status']['options']);

        // TSP Name stays the derived, read-only display of TSP ID.
        $this->assertSame('text', $columns['tsp_display_name']['type']);
        $this->assertFalse($columns['tsp_display_name']['editable']);
    }

    public function test_grid_payload_carries_the_select_type_and_options(): void
    {
        $this->report('3', ['ticket_status' => 'Open']);

        $html = Livewire::test(TechnicalReportTable::class)->html();
        preg_match('/data-managed-table-payload>(.*?)<\/script>/s', $html, $m);
        $payload = json_decode($m[1] ?? '{}', true);

        $column = collect($payload['columns'] ?? [])->firstWhere('key', 'ticket_status');

        $this->assertNotNull($column, 'Ticket Status missing from the grid payload.');
        $this->assertSame('select', $column['type'] ?? null);
        $this->assertContains('Open', $column['options'] ?? []);
    }

    public function test_customer_name_edits_pass_the_update_gate(): void
    {
        $report = $this->report('4', ['customer_name' => 'Old Name']);

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalReportTable::class)
            ->call('updateField', $report->id, 'customer_name', 'Renamed Hospital');

        $this->assertSame('Renamed Hospital', $report->fresh()->customer_name);
    }
}
