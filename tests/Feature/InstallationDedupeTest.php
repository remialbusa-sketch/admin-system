<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\Installation;
use App\Services\SourceWorkbookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationDedupeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reimport_with_changed_status_updates_instead_of_duplicating(): void
    {
        $csv = function (string $status): string {
            $path = tempnam(sys_get_temp_dir(), 'pdb-').'.csv';
            file_put_contents($path, implode(PHP_EOL, [
                'No.,CUSTOMER - NAME,CUSTOMER - ADDRESS,BRANCH,DEVICE DESCRIPTION,BRAND,SERIAL NUMBER,BU No.,SYSTEM TYPE,INSTALLATION DATE,PULLED OUT DATE,DEVICE OWNERSHIP,DEVICE STATUS,DEAL TYPE,WARRANTY STATUS,WARRANTY PERIOD',
                "1,Example Hospital,123 Main St,NCR,Analyzer,SYSMEX,SN-001,IVD-BU01,Stand Alone,2024-01-15,,Customer,{$status},Purchased,Yes,2",
            ]));

            return $path;
        };

        $first = $csv('Active');
        $second = $csv('Pulled Out');

        try {
            $importer = app(SourceWorkbookImportService::class);
            $importer->importProductDatabase($first);
            $batch = $importer->importProductDatabase($second);

            $this->assertSame(1, Installation::query()->count());
            $this->assertSame('Pulled Out', Installation::query()->value('device_status'));
            $this->assertSame(0, (int) ($batch->refresh()->metadata['deduped_rows'] ?? 0));
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    public function test_dedupe_collapses_same_identity_rows_to_the_newest_version(): void
    {
        $account = Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'account-1',
            'customer_name' => 'Example Hospital',
        ]);
        $otherAccount = Account::create([
            'source_system' => 'product_database',
            'source_record_id' => 'account-2',
            'customer_name' => 'Other Hospital',
        ]);

        // Two superseded versions + the newest version of the same device
        // (same customer, serial, description — only mutable cells differ).
        $staleA = Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => '12-aaaaaaaaaaaaaaaaaaaaaaaa',
            'serial_number' => 'SN-001',
            'device_description' => 'Analyzer',
            'device_status' => 'Active',
        ]);
        $staleB = Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => '12-bbbbbbbbbbbbbbbbbbbbbbbb',
            'serial_number' => 'SN-001',
            'device_description' => 'Analyzer',
            'device_status' => 'In Use',
        ]);
        $newest = Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => '12-cccccccccccccccccccccccc',
            'serial_number' => 'SN-001',
            'device_description' => 'Analyzer',
            'device_status' => 'Pulled Out',
        ]);
        // Same serial but a different description is a distinct row.
        $differentDevice = Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => '13-dddddddddddddddddddddddddd',
            'serial_number' => 'SN-001',
            'device_description' => 'Centrifuge',
            'device_status' => 'Active',
        ]);
        // Same serial at a different account is a distinct row.
        $differentAccount = Installation::create([
            'account_id' => $otherAccount->id,
            'source_system' => 'product_database',
            'source_record_id' => '14-eeeeeeeeeeeeeeeeeeeeeeee',
            'serial_number' => 'SN-001',
            'device_description' => 'Analyzer',
            'device_status' => 'Active',
        ]);
        // A fully blank identity row is never collapsed.
        $blank = Installation::create([
            'account_id' => $account->id,
            'source_system' => 'product_database',
            'source_record_id' => '15-ffffffffffffffffffffffff',
        ]);

        $column = CustomTableColumn::create([
            'table_key' => 'installed-products',
            'name' => 'Follow-up Notes',
            'type' => 'text',
            'position' => 1,
            'created_by' => null,
        ]);
        CustomTableColumnValue::create([
            'custom_column_id' => $column->id,
            'row_id' => $staleA->id,
            'value' => ['text' => 'stale'],
        ]);

        $removed = app(SourceWorkbookImportService::class)->dedupeProductRows();

        $this->assertSame(2, $removed);
        $this->assertTrue(Installation::query()->whereKey($newest->id)->exists());
        $this->assertTrue(Installation::query()->whereKey($differentDevice->id)->exists());
        $this->assertTrue(Installation::query()->whereKey($differentAccount->id)->exists());
        $this->assertTrue(Installation::query()->whereKey($blank->id)->exists());
        $this->assertFalse(Installation::query()->whereKey($staleA->id)->exists());
        $this->assertFalse(Installation::query()->whereKey($staleB->id)->exists());
        // Custom column values attached to removed rows are cleaned up too.
        $this->assertSame(0, CustomTableColumnValue::query()->where('custom_column_id', $column->id)->count());
    }
}
