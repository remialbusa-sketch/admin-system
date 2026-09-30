<?php

namespace Tests\Unit;

use App\Models\CustomTableColumn;
use App\Support\ImportOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ImportOptionSeeder::seed() is the shared boundary both import write
 * paths (core tables and custom tables) cross before validating a
 * status/dropdown cell. The cap must be reachable only at the CURRENT
 * MAX_OPTIONS — an option list that merely looks long (200 entries, the
 * old limit) has to keep seeding.
 */
class ImportOptionSeederCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_appends_past_the_old_200_option_limit(): void
    {
        $this->assertGreaterThan(
            200,
            ImportOptionSeeder::MAX_OPTIONS,
            'The cap must sit above the old 200 that failed live imports of high-cardinality columns.',
        );

        $options = collect(range(1, 200))->map(fn (int $i): array => [
            'index' => $i - 1,
            'label' => 'Name '.$i,
            'color' => '#64748B',
        ])->all();

        $column = CustomTableColumn::create([
            'table_key' => 'technical-reports',
            'name' => 'Customer In-Charge',
            'type' => 'dropdown',
            'settings' => ['options' => $options],
            'position' => 1,
        ]);

        ImportOptionSeeder::seed($column, 'Name 201');

        $column->refresh();
        $labels = array_column($column->settings['options'] ?? [], 'label');

        $this->assertCount(201, $labels);
        $this->assertSame('Name 201', end($labels));
    }
}
