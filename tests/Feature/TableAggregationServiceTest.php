<?php

namespace Tests\Feature;

use App\Models\ServiceRequest;
use App\Models\TechnicalReport;
use App\Services\TableAggregationService;
use App\Support\Dashboard\DashboardContext;
use App\Support\Dashboard\Widgets\BarChartWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableAggregationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function serviceRequest(array $attributes = []): ServiceRequest
    {
        return ServiceRequest::create(array_merge([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'SR-'.uniqid(),
            'customer_name' => 'Aggregation Hospital',
        ], $attributes));
    }

    private function technicalReport(array $attributes = []): TechnicalReport
    {
        return TechnicalReport::create(array_merge([
            'source_system' => 'executive_dashboard',
            'source_record_id' => 'TR-'.uniqid(),
            'reference_number' => 'TR-'.random_int(10000, 99999),
        ], $attributes));
    }

    public function test_service_request_metrics_and_datasets(): void
    {
        $this->serviceRequest(['group_status' => 'Completed', 'region' => 'Region 6', 'branch' => 'Bacolod']);
        $this->serviceRequest(['group_status' => 'Open', 'region' => 'Region 6', 'branch' => 'Bacolod']);
        $this->serviceRequest(['group_status' => 'Completed', 'region' => 'Region 1', 'branch' => 'Manila']);

        $summary = app(TableAggregationService::class)->summary('service-requests', null);

        $this->assertSame(3, $summary['metrics']['rows']);
        $this->assertSame(2, $summary['metrics']['completed']);
        $this->assertSame(1, $summary['metrics']['open']);
        $this->assertSame(66.7, $summary['metrics']['completion_rate']);

        $byGroup = collect($summary['datasets']['by_group']);
        $this->assertSame(2, $byGroup->firstWhere('label', 'Completed')['value']);

        $byBranch = collect($summary['datasets']['by_branch']);
        $this->assertSame(2, $byBranch->firstWhere('label', 'Bacolod')['value']);

        // Region scope narrows every metric and dataset.
        $scoped = app(TableAggregationService::class)->summary('service-requests', 'Region 6');
        $this->assertSame(2, $scoped['metrics']['rows']);
        $this->assertSame(2, collect($scoped['datasets']['by_branch'])->firstWhere('label', 'Bacolod')['value']);
        $this->assertNull(collect($scoped['datasets']['by_branch'])->firstWhere('label', 'Manila'));
    }

    public function test_technical_report_metrics_and_datasets(): void
    {
        $this->technicalReport(['service_status' => 'Completed', 'repair_time_hours' => 2.0, 'tsp_display_name' => 'Juan Dela Cruz']);
        $this->technicalReport(['service_status' => 'Ongoing', 'repair_time_hours' => 4.0]);

        $summary = app(TableAggregationService::class)->summary('technical-reports', null);

        $this->assertSame(2, $summary['metrics']['rows']);
        $this->assertSame(1, $summary['metrics']['completed']);
        $this->assertSame(3.0, $summary['metrics']['avg_repair_hours']);
        $this->assertSame(1, $summary['metrics']['unassigned']);

        $byTsp = collect($summary['datasets']['by_tsp']);
        $this->assertSame(1, $byTsp->firstWhere('label', 'Juan Dela Cruz')['value']);
    }

    public function test_dynamic_tables_expose_no_datasets_yet(): void
    {
        $summary = app(TableAggregationService::class)->summary('some-dynamic-table', null);

        $this->assertSame([], $summary['metrics']);
        $this->assertSame([], $summary['datasets']);
    }

    public function test_context_resolves_namespaced_datasets_and_metrics(): void
    {
        $context = DashboardContext::fromSummary('All regions', '12M', [])->withSources([
            'sr' => [
                'metrics' => ['rows' => 5],
                'datasets' => ['by_group' => [['label' => 'Open', 'value' => 2, 'total' => 2]]],
            ],
        ]);

        $this->assertSame(5, $context->metric('sr.rows'));
        $this->assertCount(1, $context->dataset('sr.by_group'));
        $this->assertSame([], $context->dataset('sr.unknown'));
        $this->assertContains('sr.by_group', $context->datasetKeys());

        // Dataset-driven widgets render a namespaced dataset unchanged.
        $resolved = (new BarChartWidget)->resolve(['dataset' => 'sr.by_group'], $context);

        $this->assertSame('Open', $resolved['data']['items'][0]['label']);
        $this->assertSame(2.0, $resolved['data']['items'][0]['value']);
    }
}
