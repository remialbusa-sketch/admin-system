<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Services\ImportMappingService;
use App\Services\SourceWorkbookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_auto_detects_header_row_and_builds_preview_for_csv(): void
    {
        $path = $this->csv([
            ['MCBTSi Executive Dashboard', '', ''],
            ['Generated 2026-06-01', '', ''],
            ['', '', ''],
            ['SR No', 'Customer', 'TICKET STATUS'],
            ['SR-1001', 'Example Hospital', 'In-Progress'],
            ['SR-1002', 'Second Hospital', 'OPEN'],
        ]);

        try {
            $analysis = app(ImportMappingService::class)->analyze($path, 'service-requests');

            $this->assertSame('csv', $analysis['ext']);
            $this->assertSame('CSV', $analysis['preview']['sheet']);
            $this->assertSame(4, $analysis['preview']['headerRow']);
            $this->assertSame(5, $analysis['preview']['dataStart']);
            $this->assertSame('A', $analysis['preview']['columns'][0]['letter']);
            $this->assertSame('SR No', $analysis['preview']['columns'][0]['label']);
            $this->assertSame('SR-1001', $analysis['preview']['columns'][0]['samples'][0]);
            $this->assertSame(2, $analysis['preview']['totalRows']);
        } finally {
            @unlink($path);
        }
    }

    public function test_mapped_import_uses_manual_mapping_for_service_requests(): void
    {
        $path = $this->csv([
            ['SR Number', 'Client', 'State'],
            ['SR-1001', 'Example Hospital', 'In-Progress'],
            ['SR-1002', 'Second Hospital', 'OPEN'],
        ]);

        try {
            $batch = app(SourceWorkbookImportService::class)->importMapped(
                $path,
                'service-requests',
                'CSV',
                ['service_request_no' => 'A', 'customer_name' => 'B', 'ticket_status' => 'C'],
                1,
                2,
            );

            $this->assertSame('completed', $batch->status);
            $this->assertSame(2, $batch->processed_rows);
            $this->assertTrue($batch->metadata['mapped_import']);
            $this->assertSame(['service_request_no' => 'A', 'customer_name' => 'B', 'ticket_status' => 'C'], $batch->metadata['mapping']);
            $this->assertSame(2, ServiceRequest::query()->whereNotNull('raw_data')->count());
            $this->assertSame('Example Hospital', ServiceRequest::query()->where('service_request_number', 'SR-1001')->value('customer_name'));
        } finally {
            @unlink($path);
        }
    }

    public function test_mapped_import_honors_a_lower_header_row_offset(): void
    {
        $path = $this->csv([
            ['MCBTSi export', ''],
            ['Do not touch this row', ''],
            ['A', 'B', 'C'],
            ['SR-2001', 'Offset Hospital', 'Completed'],
        ]);

        try {
            $batch = app(SourceWorkbookImportService::class)->importMapped(
                $path,
                'service-requests',
                'CSV',
                ['service_request_no' => 'A', 'customer_name' => 'B', 'ticket_status' => 'C'],
                3,
                4,
            );

            $this->assertSame('completed', $batch->status);
            $this->assertSame(1, $batch->processed_rows);
            $this->assertSame('Offset Hospital', ServiceRequest::query()->where('service_request_number', 'SR-2001')->value('customer_name'));
        } finally {
            @unlink($path);
        }
    }

    public function test_mapped_import_for_personnel_reads_mapped_columns_from_xlsx(): void
    {
        $path = $this->personnelWorkbook();

        try {
            $batch = app(SourceWorkbookImportService::class)->importMapped(
                $path,
                'personnel',
                'Staff',
                ['name' => 'B', 'position' => 'C', 'branch' => 'D'],
                5,
                6,
            );

            $this->assertSame('completed', $batch->status);
            $this->assertSame(2, $batch->processed_rows);
            $this->assertSame(
                ['Ana Reyes', 'Ben Lim'],
                TechnicalPersonnel::query()->orderBy('id')->pluck('name')->all(),
            );
            $this->assertSame('Cebu', TechnicalPersonnel::query()->where('name', 'Ana Reyes')->value('branch'));
        } finally {
            @unlink($path);
        }
    }

    public function test_build_header_map_converts_letters_to_positions(): void
    {
        $headers = app(ImportMappingService::class)->buildHeaderMap([
            'customer_name' => 'c',
            'brand' => 'A',
            'serial_number' => '',
            'unmapped' => '',
        ]);

        $this->assertSame([0 => 'brand', 2 => 'customer_name'], $headers);
    }

    public function test_recall_mapping_keeps_only_valid_targets_and_columns(): void
    {
        $service = app(ImportMappingService::class);
        $service->rememberMapping('service-requests', [
            'customer_name' => 'B',
            'ticket_status' => 'C',
            'not_a_target' => 'A',
            'service_request_no' => 'Q', // column does not exist in the preview
        ]);

        $recalled = $service->recallMapping('service-requests', [
            ['letter' => 'A', 'label' => 'SR Number', 'samples' => []],
            ['letter' => 'B', 'label' => 'Client', 'samples' => []],
            ['letter' => 'C', 'label' => 'State', 'samples' => []],
        ]);

        $this->assertSame('B', $recalled['customer_name']);
        $this->assertSame('C', $recalled['ticket_status']);
        $this->assertSame('', $recalled['service_request_no']);
        $this->assertArrayNotHasKey('not_a_target', $recalled);
    }

    public function test_preview_sheet_reanchors_when_header_row_moves(): void
    {
        $path = $this->csv([
            ['Banner', '', ''],
            ['SR No', 'Client'],
            ['SR-3001', 'Moved Hospital'],
        ]);

        try {
            $preview = app(ImportMappingService::class)->previewSheet($path, 'CSV', 2, 3);

            $this->assertSame(2, $preview['headerRow']);
            $this->assertSame(3, $preview['dataStart']);
            $this->assertSame('SR No', $preview['columns'][0]['label']);
            $this->assertSame('SR-3001', $preview['columns'][0]['samples'][0]);
            $this->assertSame(3, $preview['sampleRows'][0]['rowNumber']);
        } finally {
            @unlink($path);
        }
    }

    public function test_mapped_import_tracks_batch_metadata_for_product_like_flow(): void
    {
        $path = $this->csv([
            ['Ref', 'Request', 'Hospital'],
            ['TR-5001', 'SR-1001', 'Metadata Hospital'],
        ]);

        try {
            $batch = app(SourceWorkbookImportService::class)->importMapped(
                $path,
                'technical-reports',
                'CSV',
                ['reference_number' => 'A', 'service_request_number' => 'B', 'customer_name_sr' => 'C'],
                1,
                2,
            );

            $this->assertInstanceOf(ImportBatch::class, $batch);
            $this->assertSame('technical_reports', $batch->metadata['target_table']);
            $this->assertSame(1, $batch->metadata['header_row']);
            $this->assertSame(2, $batch->metadata['data_start_row']);
        } finally {
            @unlink($path);
        }
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mapping-').'.csv';

        $handle = fopen($path, 'wb');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    /**
     * A personnel-style workbook: banner rows 1-4, header on row 5, data
     * from row 6, identity columns in B/C/D - exactly the shape the wizard's
     * manual mapping is built for.
     */
    private function personnelWorkbook(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Staff');

        $sheet->setCellValue('A1', 'PERSONNEL LIST');
        $sheet->setCellValue('A2', 'Confidential');
        $sheet->setCellValue('A4', 'Code');
        $sheet->setCellValue('B5', 'Name');
        $sheet->setCellValue('C5', 'Position');
        $sheet->setCellValue('D5', 'Branch');

        $sheet->setCellValue('A6', 'P-01');
        $sheet->setCellValue('B6', 'Ana Reyes');
        $sheet->setCellValue('C6', 'Engineer');
        $sheet->setCellValue('D6', 'Cebu');

        $sheet->setCellValue('A7', 'P-02');
        $sheet->setCellValue('B7', 'Ben Lim');
        $sheet->setCellValue('C7', 'Technician');
        $sheet->setCellValue('D7', 'Davao');

        $path = tempnam(sys_get_temp_dir(), 'mapping-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
