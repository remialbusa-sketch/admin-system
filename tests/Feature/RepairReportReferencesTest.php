<?php

namespace Tests\Feature;

use App\Models\MondaySyncSetting;
use App\Models\TechnicalReport;
use App\Services\MondayApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairReportReferencesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rows exactly as the live table carries them: synced rows hold the
     * monday item id in reference_number, workbook rows keep their SN.
     *
     * @return array{0: TechnicalReport, 1: TechnicalReport, 2: TechnicalReport, 3: TechnicalReport}
     */
    private function seedRows(): array
    {
        $withName = TechnicalReport::create([
            'source_system' => 'monday:technical-reports',
            'source_record_id' => '100',
            'reference_number' => '100',
            'service_request_number' => 'SN-00100',
        ]);
        $fallback = TechnicalReport::create([
            'source_system' => 'monday:technical-reports',
            'source_record_id' => '101',
            'reference_number' => '101',
            'service_request_number' => 'SN-00101',
        ]);
        $skipped = TechnicalReport::create([
            'source_system' => 'monday:technical-reports',
            'source_record_id' => '102',
            'reference_number' => '102',
        ]);
        $workbook = TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'row-1',
            'reference_number' => 'SN-00009',
        ]);

        MondaySyncSetting::create([
            'domain' => 'technical-reports',
            'board_id' => '9',
            'core_field_map' => ['pulse1' => 'reference_number', 'text8' => 'customer_name'],
        ]);

        $client = $this->mock(MondayApiClient::class);
        $client->shouldReceive('configured')->andReturn(true);
        $client->shouldReceive('itemsPage')->once()->andReturn([
            'cursor' => null,
            'items' => [
                ['id' => '100', 'name' => 'SN-00001'],
                ['id' => '102', 'name' => ''],
            ],
        ]);

        return [$withName, $fallback, $skipped, $workbook, $client];
    }

    public function test_dry_run_counts_without_writing(): void
    {
        [$withName, $fallback, $skipped, $workbook] = $this->seedRows();

        $this->artisan('monday:repair-report-references')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame('100', $withName->fresh()->reference_number);
        $this->assertSame('101', $fallback->fresh()->reference_number);
        $this->assertSame('102', $skipped->fresh()->reference_number);
        $this->assertSame('SN-00009', $workbook->fresh()->reference_number);
        $this->assertEquals(
            ['pulse1' => 'reference_number', 'text8' => 'customer_name'],
            MondaySyncSetting::query()->firstOrFail()->core_field_map
        );
    }

    public function test_apply_restores_the_board_name_and_falls_back_to_the_service_number(): void
    {
        [$withName, $fallback, $skipped, $workbook, $client] = $this->seedRows();

        $client->shouldReceive('boardColumns')->once()->with(9)->andReturn([
            ['id' => 'pulse1', 'title' => 'Reference Number', 'type' => 'item_id'],
            ['id' => 'text8', 'title' => 'Site Name', 'type' => 'text'],
        ]);

        $this->artisan('monday:repair-report-references', ['--apply' => true])
            ->assertExitCode(0);

        // Board item name wins — it is what the title write originally stored.
        $this->assertSame('SN-00001', $withName->fresh()->reference_number);
        // Item no longer on the board: the service request number is the reference.
        $this->assertSame('SN-00101', $fallback->fresh()->reference_number);
        // No name, no service number — the id echo stays untouched.
        $this->assertSame('102', $skipped->fresh()->reference_number);
        // Non-synced rows keep their own reference.
        $this->assertSame('SN-00009', $workbook->fresh()->reference_number);
        // The echo source leaves the saved map; a real source stays.
        $this->assertEquals(
            ['text8' => 'customer_name'],
            MondaySyncSetting::query()->firstOrFail()->core_field_map
        );
    }
}
