<?php

namespace Tests\Feature;

use App\Livewire\TechnicalPersonnelTable;
use App\Models\TechnicalPersonnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TechnicalPersonnelTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_personnel_table_renders_rows_and_only_shows_approved_fields(): void
    {
        TechnicalPersonnel::create([
            'source_system' => 'personnel_list',
            'source_record_id' => 'personnel-test-1',
            'name' => 'Aballa, Nashie',
            'position' => 'Service Coordinator',
            'branch' => 'NCR',
            'region' => 'NCR',
            'raw_data' => ['name' => 'Aballa, Nashie', 'position' => 'Service Coordinator', 'branch' => 'NCR'],
        ]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(TechnicalPersonnelTable::class)
            ->assertSee('Technical Personnel')
            ->assertSee('Aballa, Nashie')
            ->assertSee('Service Coordinator')
            ->assertSee('NCR')
            ->assertDontSee('raw_data');
    }

    public function test_only_superadmin_can_edit_personnel(): void
    {
        $person = TechnicalPersonnel::create([
            'source_system' => 'personnel_list',
            'source_record_id' => 'personnel-test-2',
            'name' => 'Test Person',
            'position' => 'IT Specialist',
            'branch' => 'NCR',
        ]);

        Livewire::actingAs(User::factory()->president()->create())
            ->test(TechnicalPersonnelTable::class)
            ->call('updateField', $person->id, 'position', 'Senior IT Specialist')
            ->assertForbidden();

        Livewire::actingAs(User::factory()->superadmin()->create())
            ->test(TechnicalPersonnelTable::class)
            ->call('updateField', $person->id, 'position', 'Senior IT Specialist')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('technical_personnel', [
            'id' => $person->id,
            'position' => 'Senior IT Specialist',
        ]);
    }
}
