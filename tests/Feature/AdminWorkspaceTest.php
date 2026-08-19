<?php

namespace Tests\Feature;

use App\Livewire\RecordsTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class AdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_pages_are_available_to_authenticated_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Record intake');

        $this->actingAs($user)
            ->get('/tsp-analytics')
            ->assertOk()
            ->assertSee('TSP Analytics');

        $this->actingAs($user)
            ->get('/records')
            ->assertOk()
            ->assertSee('Records table');
    }

    public function test_records_table_can_add_a_column_and_update_a_record(): void
    {
        Livewire::test(RecordsTable::class)
            ->set('newColumnName', 'Service area')
            ->set('newColumnType', 'text')
            ->call('addColumn')
            ->assertSet('columns.6.key', 'service_area')
            ->set('selectedRecordId', 1)
            ->call('updateSelectedStatus', 'Resolved')
            ->assertSet('records.0.status', 'Resolved')
            ->call('updateSelectedField', 'priority', 'Low')
            ->assertSet('records.0.priority', 'Low')
            ->call('updateSelectedField', 'technician', 'B. Cruz')
            ->assertSet('records.0.technician', 'B. Cruz')
            ->call('updateSelectedField', 'priority', 'Invalid')
            ->assertSet('records.0.priority', 'Low');
    }

    public function test_record_details_exposes_editable_fields_and_label_selections(): void
    {
        Livewire::test(RecordsTable::class)
            ->set('selectedRecordId', 1)
            ->assertSee('Field values')
            ->assertSee('Edit each field or choose from its configured options.')
            ->assertSee('Select one or more labels for this record.')
            ->assertSeeHtml('option value="High"');
    }

    public function test_a_new_selection_can_be_added_from_the_field_selector(): void
    {
        Livewire::test(RecordsTable::class)
            ->set('selectedRecordId', 1)
            ->call('handleFieldSelection', 'status', '__add_selection__')
            ->set('selectionName', 'Awaiting parts')
            ->call('addSelectionOption')
            ->assertHasNoErrors()
            ->assertSet('records.0.status', 'Awaiting parts')
            ->assertSet('statuses.4', 'Awaiting parts');
    }

    public function test_records_table_can_replace_preview_data_with_a_csv_import(): void
    {
        $component = Livewire::test(RecordsTable::class)
            ->set('importFile', UploadedFile::fake()->createWithContent(
                'service-records.csv',
                "Ticket ID,Technician,Status\nSR-2001,A. Cruz,New\nSR-2002,R. Lim,Resolved\n",
            ))
            ->call('importRecords');

        $component
            ->assertHasNoErrors()
            ->assertSet('importedFileName', 'service-records.csv')
            ->assertSet('records.0.ticket_id', 'SR-2001')
            ->assertSet('records.1.status', 'Resolved');
    }
}
