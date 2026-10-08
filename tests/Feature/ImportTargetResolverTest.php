<?php

namespace Tests\Feature;

use App\Models\CustomTableColumn;
use App\Support\ImportTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The import wizard's field catalog (ImportTargetResolver is the single
 * source for the classic page, auto-mapping, recall and validation).
 * Monday sync auto-creates board columns as customs, which can duplicate
 * a managed field's label ("Customer Name" beside core customer_name):
 * twin labels are indistinguishable in the mapping dropdown and route
 * workbook headers into shadow columns — the managed field stays the
 * only target for that label, other customs are still offered.
 */
class ImportTargetResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_columns_duplicating_a_managed_label_are_not_offered_as_targets(): void
    {
        $twin = CustomTableColumn::create([
            'table_key' => 'installed-products',
            'name' => 'Customer Name',
            'type' => 'text',
            'position' => 1,
        ]);
        $caseTwin = CustomTableColumn::create([
            'table_key' => 'installed-products',
            'name' => 'BRAND',
            'type' => 'text',
            'position' => 2,
        ]);
        $keep = CustomTableColumn::create([
            'table_key' => 'installed-products',
            'name' => 'QR Code',
            'type' => 'text',
            'position' => 3,
        ]);

        $targets = ImportTargetResolver::for('installed-products');

        $keys = array_column($targets['fields'], 'key');

        $this->assertNotContains('custom_'.$twin->id, $keys);
        $this->assertNotContains('custom_'.$caseTwin->id, $keys);
        $this->assertContains('custom_'.$keep->id, $keys);
        $this->assertContains('customer_name', $keys);

        // Exactly one "Customer Name" target remains: the managed field.
        $customerNameFields = array_values(array_filter(
            $targets['fields'],
            fn (array $field): bool => $field['label'] === 'Customer Name'
        ));

        $this->assertCount(1, $customerNameFields);
        $this->assertSame('customer_name', $customerNameFields[0]['key']);
    }
}
