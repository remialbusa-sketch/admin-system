<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Livewire\TablesList;
use App\Models\DynamicTable;
use App\Models\TablePin;
use App\Models\User;
use App\Support\TableCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class TablePinTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_and_unpin_core_table(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TablesList::class)
            ->call('togglePin', 'installed-products')
            ->assertSet('pinnedKeys', ['installed-products'])
            ->assertDispatched('table-pins-updated');

        $this->assertDatabaseHas('table_pins', ['user_id' => $user->id, 'table_key' => 'installed-products']);

        Livewire::actingAs($user)
            ->test(TablesList::class)
            ->call('togglePin', 'installed-products')
            ->assertSet('pinnedKeys', [])
            ->assertDispatched('table-pins-updated');

        $this->assertDatabaseMissing('table_pins', ['user_id' => $user->id, 'table_key' => 'installed-products']);
    }

    public function test_pin_ignores_unknown_key(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TablesList::class)
            ->call('togglePin', 'does-not-exist')
            ->assertSet('pinnedKeys', []);

        $this->assertDatabaseCount('table_pins', 0);
    }

    public function test_table_catalog_resolves_core_and_dynamic_tables(): void
    {
        $catalog = app(TableCatalog::class);

        $core = $catalog->resolve('installed-products');
        $this->assertSame('installed-products', $core['route']);
        $this->assertSame('Product Database', $core['label']);

        DynamicTable::create(['key' => 'equipment', 'name' => 'Equipment', 'created_by' => null]);
        $dynamic = $catalog->resolve('equipment');
        $this->assertSame('tables.show', $dynamic['route']);
        $this->assertSame(['table' => 'equipment'], $dynamic['params']);
        $this->assertSame('Equipment', $dynamic['label']);

        $this->assertNull($catalog->resolve('nope'));
    }

    public function test_pins_are_per_user(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        Livewire::actingAs($a)->test(TablesList::class)->call('togglePin', 'personnel');

        $this->assertCount(1, TablePin::keysFor($a->id));
        $this->assertCount(0, TablePin::keysFor($b->id));
    }

    public function test_missing_table_pins_migration_never_500s_pages(): void
    {
        // Regression: the sidebar reads TablePin::keysFor() on every page
        // render, so a machine that pulled code without running the table_pins
        // migration used to take down the whole app (e.g. the Users page
        // right after saving an account). It must degrade to "no pins".
        Schema::drop('table_pins');

        $this->assertFalse(TablePin::available());
        $this->assertSame([], TablePin::keysFor(1));

        // The Users page (layout + sidebar included) still renders fine.
        User::factory()->superadmin()->create();
        $this->actingAs(User::query()->firstOrFail())
            ->get('/users')
            ->assertOk();

        // Pinning attempts report a notice instead of throwing.
        Livewire::actingAs(User::query()->firstOrFail())
            ->test(TablesList::class)
            ->call('togglePin', 'installed-products')
            ->assertHasNoErrors();
    }

    public function test_pinned_dynamic_table_orders_and_resolves_in_sidebar(): void
    {
        $user = User::factory()->create();
        DynamicTable::create(['key' => 'equipment', 'name' => 'Equipment', 'created_by' => null]);

        Livewire::actingAs($user)
            ->test(TablesList::class)
            ->call('togglePin', 'equipment');

        // Sidebar nav items resolve through the catalog, ordered by position.
        $items = app(TableCatalog::class)->navForKeys(TablePin::keysFor($user->id));
        $this->assertCount(1, $items);
        $this->assertSame('tables.show', $items[0]['route']);
        $this->assertSame('Equipment', $items[0]['label']);
    }

    public function test_sidebar_lists_only_pinned_tables_core_included(): void
    {
        $user = User::factory()->create();
        DynamicTable::create(['key' => 'equipment', 'name' => 'Equipment', 'created_by' => $user->id]);
        DynamicTable::create(['key' => 'fleet', 'name' => 'Fleet', 'created_by' => null]);

        // Nothing pinned: the Tables group is empty (its empty-state copy is
        // live again) — no core table shows either, and "All tables" is the
        // way back to everything.
        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->assertDontSee('Product Database')
            ->assertDontSee('Equipment')
            ->assertSee('No pinned tables yet')
            ->assertSee('All tables');

        // Pin order rules: equipment first, then a core table.
        Livewire::actingAs($user)->test(TablesList::class)->call('togglePin', 'equipment');
        Livewire::actingAs($user)->test(TablesList::class)->call('togglePin', 'installed-products');

        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->assertSee('Equipment')
            ->assertSee('Product Database')
            ->assertSeeInOrder(['Equipment', 'Product Database'])
            ->assertDontSee('Technical Personnel') // unpinned core hides, like any table
            ->assertDontSee('Fleet');             // unpinned dynamic hides

        // Unpinning a core table removes it — pins follow through for core.
        Livewire::actingAs($user)->test(TablesList::class)->call('togglePin', 'installed-products');

        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->assertDontSee('Product Database')
            ->assertSee('Equipment');

        // A pin pointing at a table the user cannot open (ownerless tables
        // are superadmin-only) is dropped instead of rendering a dead link.
        TablePin::create(['user_id' => $user->id, 'table_key' => 'fleet', 'position' => 1]);

        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->assertSee('Equipment')
            ->assertDontSee('Fleet');
    }
}
