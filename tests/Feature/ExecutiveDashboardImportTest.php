<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ServiceRequest;
use App\Models\TechnicalReport;
use App\Services\SourceWorkbookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutiveDashboardImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_executive_dashboard_import_records_source_metadata_and_stable_row_identity(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'executive-').'.csv';
        file_put_contents($path, implode(PHP_EOL, [
            'Service Request,Customer Name,TICKET STATUS,Date Created',
            'SR-1001,Example Hospital,In-Progress,2026-06-01 10:00:00',
            ',Second Hospital,OPEN,2026-06-02 10:00:00',
        ]));

        try {
            $batch = app(SourceWorkbookImportService::class)->importServiceRequests($path);

            $this->assertSame('completed', $batch->status);
            $this->assertSame(2, $batch->processed_rows);
            $this->assertSame('Service Requests', $batch->source_sheet);
            $this->assertSame('service_requests', $batch->metadata['target_table']);
            $this->assertSame('row-3', ServiceRequest::query()->where('customer_name', 'Second Hospital')->value('source_record_id'));
            $this->assertSame(2, ServiceRequest::query()->whereNotNull('raw_data')->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_technical_report_import_links_to_request_using_request_number_and_keeps_report_identity(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'executive-reports-').'.csv';
        file_put_contents($path, implode(PHP_EOL, [
            'Service Request Number,Reference Number,Name,Customer Name - SR,SERVICE STATUS,Date Created,Repair Time (Hours)',
            'SR-1001,TR-1001,SR-1001,Example Hospital,Completed,2026-06-03 10:00:00,1.5',
        ]));

        try {
            ServiceRequest::create([
                'source_system' => SourceWorkbookImportService::EXECUTIVE_SOURCE,
                'source_record_id' => 'SR-1001',
                'service_request_number' => 'SR-1001',
            ]);

            $batch = app(SourceWorkbookImportService::class)->importTechnicalReports($path);
            $report = TechnicalReport::query()->firstOrFail();

            $this->assertSame('completed', $batch->status);
            $this->assertSame('TR-1001', $report->source_record_id);
            $this->assertNotNull($report->service_request_id);
            $this->assertSame(1.5, (float) $report->repair_time_hours);
            $this->assertSame('technical_reports', $batch->metadata['target_table']);
        } finally {
            @unlink($path);
        }
    }
}
