<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\DynamicTable;
use App\Livewire\Sidebar;
use App\Livewire\TablesList;
use App\Models\DynamicRow;
use App\Models\DynamicTable as DynamicTableModel;
use App\Models\TablePin;
use App\Models\TableShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase B: personal dynamic tables are private — owner plus explicit
 * per-user shares (table_shares mirrors dashboard_shares). The five core
 * tables stay open to everyone and always appear in the sidebar.
 */
class TableSharingTest extends TestCase
{
    use RefreshDatabase;

    /** A table owner with the Admin permission (may create/import tables). */
    private function owner(): User
    {
        return User::factory()->nationalManager()->state(['permission' => 'admin'])->create();
    }

    private function tableFor(User $owner, string $key = 'private-notes', string $name = 'Private notes'): DynamicTableModel
    {
        return DynamicTableModel::create([
            'key' => $key,
            'name' => $name,
            'created_by' => $owner->id,
        ]);
    }

    private function share(DynamicTableModel $table, User $user, string $permission, User $sharedBy): TableShare
    {
        return TableShare::create([
            'dynamic_table_id' => $table->id,
            'user_id' => $user->id,
            'permission' => $permission,
            'shared_by' => $sharedBy->id,
        ]);
    }

    public function test_a_dynamic_table_is_private_until_shared(): void
    {
        $owner = $this->owner();
        $this->tableFor($owner);

        $stranger = User::factory()->president()->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($owner)->get(route('tables.show', 'private-notes'))->assertOk();
        $this->actingAs($superadmin)->get(route('tables.show', 'private-notes'))->assertOk();

        // Nobody else may even open it — no share exists yet.
        $this->actingAs($stranger)->get(route('tables.show', 'private-notes'))->assertForbidden();

        $this->share(
            DynamicTableModel::query()->where('key', 'private-notes')->firstOrFail(),
            $stranger,
            'view',
            $owner,
        );

        $this->actingAs($stranger)->get(route('tables.show', 'private-notes'))->assertOk();
    }

    public function test_the_tables_page_lists_core_yours_and_shared_but_not_strangers(): void
    {
        $owner = $this->owner();
        $this->tableFor($owner, 'owner-secret-table', 'Owner secret table');

        $stranger = User::factory()->president()->create();

        // The five core tables are listed for everyone; a stranger's table is not.
        $this->actingAs($stranger)
            ->get(route('tables'))
            ->assertOk()
            ->assertSee('Product Database')
            ->assertDontSee('Owner secret table');

        $this->actingAs($owner)
            ->get(route('tables'))
            ->assertOk()
            ->assertSee('Owner secret table');

        $this->share(
            DynamicTableModel::query()->where('key', 'owner-secret-table')->firstOrFail(),
            $stranger,
            'view',
            $owner,
        );

        $this->actingAs($stranger)
            ->get(route('tables'))
            ->assertSee('Owner secret table');
    }

    public function test_a_view_share_grants_read_but_never_writes(): void
    {
        $owner = $this->owner();
        $table = $this->tableFor($owner);

        // Full Admin permission: every role-level gate passes, so the
        // per-table view share is the only thing that can block them.
        $viewer = User::factory()->nationalManager()->state(['permission' => 'admin'])->create();
        $this->share($table, $viewer, 'view', $owner);

        Livewire::actingAs($viewer)
            ->test(DynamicTable::class, ['table' => 'private-notes'])
            ->call('createRecord', ['name' => 'Sneaky row'])
            ->assertForbidden();

        $this->assertSame(0, DynamicRow::query()->where('table_key', 'private-notes')->count());

        // Table-level tooling (monday live-pull etc.) needs edit too.
        Livewire::actingAs($viewer)
            ->test(DynamicTable::class, ['table' => 'private-notes'])
            ->call('toggleMondayPull')
            ->assertForbidden();
    }

    public function test_an_edit_share_edits_for_editors_but_the_viewer_role_still_loses(): void
    {
        $owner = $this->owner();
        $table = $this->tableFor($owner);

        $editor = User::factory()->nationalManager()->state(['permission' => 'editor'])->create();
        $this->share($table, $editor, 'edit', $owner);

        Livewire::actingAs($editor)
            ->test(DynamicTable::class, ['table' => 'private-notes'])
            ->call('createRecord', ['name' => 'Shared edit'])
            ->assertHasNoErrors();

        $this->assertSame(1, DynamicRow::query()->where('table_key', 'private-notes')->count());

        // A view/edit share never overrides the global role: viewers stay read-only.
        $viewerRole = User::factory()->president()->create();
        $this->share($table, $viewerRole, 'edit', $owner);

        Livewire::actingAs($viewerRole)
            ->test(DynamicTable::class, ['table' => 'private-notes'])
            ->call('createRecord', ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertSame(1, DynamicRow::query()->where('table_key', 'private-notes')->count());
    }

    public function test_only_the_owner_manages_shares(): void
    {
        $owner = $this->owner();
        $table = $this->tableFor($owner);

        $sharedEditor = User::factory()->nationalManager()->state(['permission' => 'editor'])->create();
        $this->share($table, $sharedEditor, 'edit', $owner);

        // Even an edit-share holder cannot open the share controls.
        Livewire::actingAs($sharedEditor)
            ->test(TablesList::class)
            ->call('openShareModal', 'private-notes')
            ->assertForbidden();

        $stranger = User::factory()->president()->create();
        Livewire::actingAs($stranger)
            ->test(TablesList::class)
            ->call('unshareTable', TableShare::query()->firstOrFail()->id)
            ->assertForbidden();

        // The owner can share and unshare.
        Livewire::actingAs($owner)
            ->test(TablesList::class)
            ->call('openShareModal', 'private-notes')
            ->assertSet('shareTableKey', 'private-notes');

        $second = User::factory()->president()->create();
        Livewire::actingAs($owner)
            ->test(TablesList::class)
            ->call('openShareModal', 'private-notes')
            ->set('shareUserId', $second->id)
            ->set('sharePermission', 'view')
            ->call('shareTable')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('table_shares', [
            'dynamic_table_id' => $table->id,
            'user_id' => $second->id,
            'permission' => 'view',
        ]);

        $shareId = TableShare::query()->where('user_id', $second->id)->value('id');

        Livewire::actingAs($owner)
            ->test(TablesList::class)
            ->call('unshareTable', $shareId)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('table_shares', ['id' => $shareId]);
    }

    public function test_the_sidebar_lists_the_five_core_tables_for_everyone(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->assertSee('Product Database')
            ->assertSee('Service Requests')
            ->assertSee('Technical Reports')
            ->assertSee('History Reports')
            ->assertSee('Technical Personnel');

        // A stranger's private table never appears (pinned or not).
        $owner = $this->owner();
        $table = $this->tableFor($owner, 'secret-fleet', 'Secret fleet data');
        $this->assertNotNull($table);

        TablePin::create(['user_id' => $user->id, 'table_key' => 'secret-fleet', 'position' => 0]);

        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->assertDontSee('Secret fleet data');
    }

    public function test_data_source_pickers_hide_tables_the_user_cannot_open(): void
    {
        $owner = $this->owner();
        $this->tableFor($owner, 'hidden-source', 'Hidden source');

        $stranger = User::factory()->nationalManager()->state(['permission' => 'admin'])->create();

        Livewire::actingAs($stranger)
            ->test(Dashboard::class)
            ->assertViewHas('tableOptions', function (array $options): bool {
                return ! in_array('hidden-source', array_column($options, 'key'), true);
            });

        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->assertViewHas('tableOptions', function (array $options): bool {
                return in_array('hidden-source', array_column($options, 'key'), true);
            });
    }

    public function test_classic_import_requires_edit_access_to_the_table(): void
    {
        $owner = $this->owner();
        $table = $this->tableFor($owner);

        $otherAdmin = User::factory()->nationalManager()->state(['permission' => 'admin'])->create();

        // Role is enough to reach core imports, but not someone's private table.
        $this->actingAs($otherAdmin)->get(route('tables.import.classic', 'private-notes'))->assertForbidden();
        $this->assertTrue(
            Gate::forUser($otherAdmin)->allows('importTable', 'installed-products'),
        );

        // The owner's own import page still opens.
        $this->actingAs($owner)->get(route('tables.import.classic', 'private-notes'))->assertOk();

        // An edit share unlocks importing too.
        $this->share($table, $otherAdmin, 'edit', $owner);
        $this->assertTrue(Gate::forUser($otherAdmin)->allows('importTable', 'private-notes'));

        $this->actingAs($otherAdmin)->get(route('tables.import.classic', 'private-notes'))->assertOk();
    }
}
