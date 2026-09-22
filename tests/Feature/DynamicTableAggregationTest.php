<?php

namespace Tests\Feature;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\User;
use App\Services\TableAggregationService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\Widgets\BarChartWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicTableAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_table_aggregation_exposes_datasets_for_charts(): void
    {
        $user = User::factory()->create();
        $table = DynamicTable::create(['key' => 'projects', 'name' => 'Projects', 'created_by' => $user->id]);
        $colStatus = CustomTableColumn::create([
            'table_key' => $table->key,
            'name' => 'Status',
            'type' => 'status',
            'position' => 0,
            'created_by' => $user->id,
            'settings' => ['options' => [['index' => 0, 'label' => 'Open'], ['index' => 1, 'label' => 'Done']]],
        ]);
        $colBranch = CustomTableColumn::create([
            'table_key' => $table->key,
            'name' => 'Branch',
            'type' => 'text',
            'position' => 1,
            'created_by' => $user->id,
        ]);

        $row1 = DynamicRow::create(['table_key' => $table->key, 'name' => 'Row 1', 'source_system' => 'test', 'source_record_id' => '1']);
        $row2 = DynamicRow::create(['table_key' => $table->key, 'name' => 'Row 2', 'source_system' => 'test', 'source_record_id' => '2']);
        $row3 = DynamicRow::create(['table_key' => $table->key, 'name' => 'Row 3', 'source_system' => 'test', 'source_record_id' => '3']);

        CustomTableColumnValue::create(['custom_column_id' => $colStatus->id, 'row_id' => $row1->id, 'value' => ['index' => 0, 'label' => 'Open'], 'value_text' => 'Open', 'value_number' => 0]);
        CustomTableColumnValue::create(['custom_column_id' => $colStatus->id, 'row_id' => $row2->id, 'value' => ['index' => 1, 'label' => 'Done'], 'value_text' => 'Done', 'value_number' => 1]);
        CustomTableColumnValue::create(['custom_column_id' => $colStatus->id, 'row_id' => $row3->id, 'value' => ['index' => 0, 'label' => 'Open'], 'value_text' => 'Open', 'value_number' => 0]);
        CustomTableColumnValue::create(['custom_column_id' => $colBranch->id, 'row_id' => $row1->id, 'value' => ['text' => 'Manila'], 'value_text' => 'Manila']);
        CustomTableColumnValue::create(['custom_column_id' => $colBranch->id, 'row_id' => $row2->id, 'value' => ['text' => 'Cebu'], 'value_text' => 'Cebu']);
        CustomTableColumnValue::create(['custom_column_id' => $colBranch->id, 'row_id' => $row3->id, 'value' => ['text' => 'Manila'], 'value_text' => 'Manila']);

        $summary = app(TableAggregationService::class)->summary($table->key, null);

        $this->assertSame(3, $summary['metrics']['rows']);
        $this->assertArrayHasKey('by_status', $summary['datasets']);
        $this->assertArrayHasKey('by_branch', $summary['datasets']);

        $byStatus = collect($summary['datasets']['by_status']);
        $this->assertSame(2, $byStatus->firstWhere('label', 'Open')['value']);
        $this->assertSame(1, $byStatus->firstWhere('label', 'Done')['value']);

        $context = DashboardContext::fromSummary('All regions', '12M', [])->withSources(['proj' => ['metrics' => $summary['metrics'], 'datasets' => $summary['datasets']]]);
        $this->assertContains('proj.by_status', $context->datasetKeys());

        $resolved = (new BarChartWidget)->resolve(['dataset' => 'proj.by_status'], $context);
        $this->assertSame('Open', $resolved['data']['items'][0]['label']);
    }
}
