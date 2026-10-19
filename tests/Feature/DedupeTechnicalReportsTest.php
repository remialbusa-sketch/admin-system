<?php

namespace Tests\Feature;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\TechnicalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupeTechnicalReportsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: TechnicalReport, 1: TechnicalReport, 2: TechnicalReport, 3: CustomTableColumn, 4: CustomTableColumn}
     */
    private function seedRows(): array
    {
        $repairTime = CustomTableColumn::create([
            'table_key' => 'technical-reports',
            'name' => 'REPAIR TIME',
            'type' => 'text',
            'position' => 0,
        ]);
        $remarks = CustomTableColumn::create([
            'table_key' => 'technical-reports',
            'name' => 'REMARKS',
            'type' => 'text',
            'position' => 1,
        ]);

        $monday = TechnicalReport::create([
            'source_system' => 'monday:technical-reports',
            'source_record_id' => '100',
            'reference_number' => 'SN-00001',
            'customer_name' => 'Live Customer',
        ]);
        $duplicate = TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'row-1',
            'reference_number' => 'SN-00001',
            'customer_name' => 'Old Customer',
            'job_done' => 'Fixed',
            'repair_time_hours' => '2.5',
        ]);
        $kept = TechnicalReport::create([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'row-2',
            'reference_number' => 'SN-00099',
        ]);

        CustomTableColumnValue::create([
            'custom_column_id' => $remarks->id,
            'row_id' => $monday->id,
            'value' => ['text' => 'keep me'],
            'value_text' => 'keep me',
        ]);
        CustomTableColumnValue::create([
            'custom_column_id' => $repairTime->id,
            'row_id' => $duplicate->id,
            'value' => ['text' => '2.5 hrs'],
            'value_text' => '2.5 hrs',
        ]);
        CustomTableColumnValue::create([
            'custom_column_id' => $remarks->id,
            'row_id' => $duplicate->id,
            'value' => ['text' => 'old remark'],
            'value_text' => 'old remark',
        ]);

        return [$monday, $duplicate, $kept, $repairTime, $remarks];
    }

    public function test_dry_run_reports_counts_without_touching_data(): void
    {
        [$monday, $duplicate, , $repairTime] = $this->seedRows();

        $this->artisan('monday:dedupe-technical-reports')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertDatabaseHas('technical_reports', ['id' => $duplicate->id]);
        $this->assertNull($monday->fresh()->repair_time_hours);
        $this->assertNull($monday->fresh()->job_done);
        $this->assertDatabaseMissing('table_custom_column_values', ['custom_column_id' => $repairTime->id, 'row_id' => $monday->id]);
    }

    public function test_apply_copies_missing_values_then_deletes_only_the_duplicate(): void
    {
        [$monday, $duplicate, $kept, $repairTime, $remarks] = $this->seedRows();

        $this->artisan('monday:dedupe-technical-reports', ['--apply' => true])
            ->assertExitCode(0);

        // The duplicate workbook row is gone, its workbook-only sibling stays.
        $this->assertDatabaseMissing('technical_reports', ['id' => $duplicate->id]);
        $this->assertDatabaseHas('technical_reports', ['id' => $kept->id]);
        $this->assertDatabaseMissing('table_custom_column_values', ['row_id' => $duplicate->id]);

        // Fill-empty only: live values survive, workbook-only values move over.
        $monday->refresh();
        $this->assertSame('Live Customer', $monday->customer_name);
        $this->assertSame('Fixed', $monday->job_done);
        $this->assertSame('2.50', $monday->repair_time_hours);

        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $repairTime->id,
            'row_id' => $monday->id,
            'value_text' => '2.5 hrs',
        ]);
        $this->assertDatabaseHas('table_custom_column_values', [
            'custom_column_id' => $remarks->id,
            'row_id' => $monday->id,
            'value_text' => 'keep me',
        ]);
    }
}
