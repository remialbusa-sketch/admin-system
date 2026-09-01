<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;

class ImportMappingService
{
    /** Physical rows scanned for header detection + the preview window. */
    private const SCAN_ROWS = 30;

    /**
     * Mappable columns per managed table. Keys are the normalized header keys
     * SourceWorkbookImportService reads via value($data, ...) - they must never
     * drift from those handlers. "required" marks identity-critical fields the
     * wizard refuses to import without.
     */
    public const TARGETS = [
        'installed-products' => [
            'label' => 'Product Database',
            'sheet' => 'PDB Data',
            'fields' => [
                ['key' => 'customer_name', 'label' => 'Customer Name', 'required' => true, 'kind' => 'text'],
                ['key' => 'device_description', 'label' => 'Device Description', 'required' => true, 'kind' => 'text'],
                ['key' => 'brand', 'label' => 'Brand', 'required' => false, 'kind' => 'text'],
                ['key' => 'machine_type', 'label' => 'Machine Type', 'required' => false, 'kind' => 'text'],
                ['key' => 'serial_number', 'label' => 'Serial Number', 'required' => false, 'kind' => 'text'],
                ['key' => 'customer_address', 'label' => 'Customer Address', 'required' => false, 'kind' => 'text'],
                ['key' => 'hospital_section', 'label' => 'Hospital Section', 'required' => false, 'kind' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'required' => false, 'kind' => 'text'],
                ['key' => 'region', 'label' => 'Region', 'required' => false, 'kind' => 'text'],
                ['key' => 'bu_no', 'label' => 'BU No.', 'required' => false, 'kind' => 'text'],
                ['key' => 'system_type', 'label' => 'Equipment Type (System)', 'required' => false, 'kind' => 'text'],
                ['key' => 'installation_date', 'label' => 'Installation Date', 'required' => false, 'kind' => 'date'],
                ['key' => 'pulled_out_date', 'label' => 'Pulled-out Date', 'required' => false, 'kind' => 'date'],
                ['key' => 'device_status', 'label' => 'Device Status', 'required' => false, 'kind' => 'text'],
                ['key' => 'device_ownership', 'label' => 'Device Ownership', 'required' => false, 'kind' => 'text'],
                ['key' => 'deal_type', 'label' => 'Deal Type', 'required' => false, 'kind' => 'text'],
                ['key' => 'charge_to', 'label' => 'Charge To', 'required' => false, 'kind' => 'text'],
                ['key' => 'warranty_status', 'label' => 'Warranty Status', 'required' => false, 'kind' => 'text'],
                ['key' => 'warranty_period', 'label' => 'Warranty Period (Years)', 'required' => false, 'kind' => 'number'],
                ['key' => 'warranty_date_end', 'label' => 'Warranty Date End', 'required' => false, 'kind' => 'date'],
                ['key' => 'service_contract_status', 'label' => 'Service Contract Status', 'required' => false, 'kind' => 'text'],
                ['key' => 'service_contract_amount', 'label' => 'Service Contract Amount', 'required' => false, 'kind' => 'number'],
                ['key' => 'service_contract_start', 'label' => 'Service Contract Start', 'required' => false, 'kind' => 'date'],
                ['key' => 'service_contract_end', 'label' => 'Service Contract End', 'required' => false, 'kind' => 'date'],
                ['key' => 'annual_bu_charge', 'label' => 'Annual BU Charge', 'required' => false, 'kind' => 'number'],
            ],
        ],
        'service-requests' => [
            'label' => 'Service Requests',
            'sheet' => 'Service Requests',
            'fields' => [
                ['key' => 'service_request_no', 'label' => 'Service Request No.', 'required' => true, 'kind' => 'text'],
                ['key' => 'service_request', 'label' => 'Service Request Code', 'required' => false, 'kind' => 'text'],
                ['key' => 'customer_name', 'label' => 'Customer Name', 'required' => false, 'kind' => 'text'],
                ['key' => 'ticket_status', 'label' => 'Ticket Status', 'required' => false, 'kind' => 'text'],
                ['key' => 'group', 'label' => 'Group', 'required' => false, 'kind' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'required' => false, 'kind' => 'text'],
                ['key' => 'tsp_assigned', 'label' => 'TSP Assigned', 'required' => false, 'kind' => 'text'],
                ['key' => 'reassigned_tsp', 'label' => 'Reassigned TSP', 'required' => false, 'kind' => 'text'],
                ['key' => 'coordinator', 'label' => 'Coordinator', 'required' => false, 'kind' => 'text'],
                ['key' => 'requesting_entity', 'label' => 'Requesting Entity', 'required' => false, 'kind' => 'text'],
                ['key' => 'requestors_name', 'label' => 'Requestor Name', 'required' => false, 'kind' => 'text'],
                ['key' => 'requestor_s_email', 'label' => 'Requestor Email', 'required' => false, 'kind' => 'text'],
                ['key' => 'phone_number', 'label' => 'Phone Number', 'required' => false, 'kind' => 'text'],
                ['key' => 'regions', 'label' => 'Region (Raw)', 'required' => false, 'kind' => 'text'],
                ['key' => 'assign_region', 'label' => 'Assigned Region', 'required' => false, 'kind' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'required' => false, 'kind' => 'text'],
                ['key' => 'contract_type', 'label' => 'Contract Type', 'required' => false, 'kind' => 'text'],
                ['key' => 'type_of_request', 'label' => 'Type of Request', 'required' => false, 'kind' => 'text'],
                ['key' => 'service_type', 'label' => 'Service Type', 'required' => false, 'kind' => 'text'],
                ['key' => 'brand', 'label' => 'Brand', 'required' => false, 'kind' => 'text'],
                ['key' => 'machine_type', 'label' => 'Machine Type', 'required' => false, 'kind' => 'text'],
                ['key' => 'serial_no', 'label' => 'Serial No.', 'required' => false, 'kind' => 'text'],
                ['key' => 'concerns', 'label' => 'Concerns', 'required' => false, 'kind' => 'text'],
                ['key' => 'date_needed', 'label' => 'Date Needed', 'required' => false, 'kind' => 'date'],
                ['key' => 'service_indicator', 'label' => 'Service Indicator', 'required' => false, 'kind' => 'text'],
                ['key' => 'device_ownership', 'label' => 'Device Ownership', 'required' => false, 'kind' => 'text'],
            ],
        ],
        'technical-reports' => [
            'label' => 'Technical Reports',
            'sheet' => 'Technical Reports',
            'fields' => [
                ['key' => 'reference_number', 'label' => 'Reference Number', 'required' => true, 'kind' => 'text'],
                ['key' => 'service_request_number', 'label' => 'Service Request Number', 'required' => false, 'kind' => 'text'],
                ['key' => 'name', 'label' => 'Report Name', 'required' => false, 'kind' => 'text'],
                ['key' => 'ticket_status', 'label' => 'Ticket Status', 'required' => false, 'kind' => 'text'],
                ['key' => 'service_status', 'label' => 'Service Status', 'required' => false, 'kind' => 'text'],
                ['key' => 'customer_name_sr', 'label' => 'Customer Name (SR)', 'required' => false, 'kind' => 'text'],
                ['key' => 'service_start_date_time', 'label' => 'Service Start Date/Time', 'required' => false, 'kind' => 'datetime'],
                ['key' => 'service_end_date_time', 'label' => 'Service End Date/Time', 'required' => false, 'kind' => 'datetime'],
                ['key' => 'tsp_name', 'label' => 'TSP Name', 'required' => false, 'kind' => 'text'],
                ['key' => 'tsp', 'label' => 'TSP', 'required' => false, 'kind' => 'text'],
                ['key' => 'brand', 'label' => 'Brand', 'required' => false, 'kind' => 'text'],
                ['key' => 'machine_type', 'label' => 'Machine Type', 'required' => false, 'kind' => 'text'],
                ['key' => 'job_done', 'label' => 'Job Done', 'required' => false, 'kind' => 'text'],
                ['key' => 'parts_replaced', 'label' => 'Parts Replaced', 'required' => false, 'kind' => 'text'],
                ['key' => 'recommendation', 'label' => 'Recommendation', 'required' => false, 'kind' => 'text'],
                ['key' => 'repair_time_hours', 'label' => 'Repair Time (Hours)', 'required' => false, 'kind' => 'number'],
                ['key' => 'response_time', 'label' => 'Response Time (Hours)', 'required' => false, 'kind' => 'number'],
                ['key' => 'report_copy_testing', 'label' => 'Report Copy / Files', 'required' => false, 'kind' => 'text'],
            ],
        ],
        'history-reports' => [
            'label' => 'History Reports',
            'sheet' => 'MCBTSi TSMS',
            'fields' => [
                ['key' => 'timestamp', 'label' => 'Timestamp', 'required' => true, 'kind' => 'datetime'],
                ['key' => 'csr', 'label' => 'CSR No.', 'required' => false, 'kind' => 'text'],
                ['key' => 'problem_complaints', 'label' => 'Problem / Complaints', 'required' => false, 'kind' => 'text'],
                ['key' => 'brand', 'label' => 'Brand', 'required' => false, 'kind' => 'text'],
                ['key' => 'model', 'label' => 'Model', 'required' => false, 'kind' => 'text'],
                ['key' => 'serial_no', 'label' => 'Serial No.', 'required' => false, 'kind' => 'text'],
                ['key' => 'account_name', 'label' => 'Account Name', 'required' => false, 'kind' => 'text'],
                ['key' => 'address', 'label' => 'Address', 'required' => false, 'kind' => 'text'],
                ['key' => 'service_type', 'label' => 'Service Type', 'required' => false, 'kind' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'required' => false, 'kind' => 'text'],
                ['key' => 'job_done', 'label' => 'Job Done', 'required' => false, 'kind' => 'text'],
                ['key' => 'parts_replaced', 'label' => 'Parts Replaced', 'required' => false, 'kind' => 'text'],
                ['key' => 'recommendation_remarks', 'label' => 'Recommendation / Remarks', 'required' => false, 'kind' => 'text'],
                ['key' => 'log_in_date', 'label' => 'Log-in Date', 'required' => false, 'kind' => 'date'],
                ['key' => 'login_time', 'label' => 'Log-in Time', 'required' => false, 'kind' => 'text'],
                ['key' => 'service_start_time', 'label' => 'Service Start Time', 'required' => false, 'kind' => 'datetime'],
                ['key' => 'log_out_date', 'label' => 'Log-out Date', 'required' => false, 'kind' => 'date'],
                ['key' => 'log_out_time', 'label' => 'Log-out Time', 'required' => false, 'kind' => 'text'],
                ['key' => 'tsr', 'label' => 'TSR No.', 'required' => false, 'kind' => 'text'],
                ['key' => 'tsp_name', 'label' => 'TSP Name', 'required' => false, 'kind' => 'text'],
                ['key' => 'tsp_workwith', 'label' => 'TSP Worked With', 'required' => false, 'kind' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'required' => false, 'kind' => 'text'],
                ['key' => 'document_reference_number', 'label' => 'Document Reference No.', 'required' => false, 'kind' => 'text'],
                ['key' => 'document_reference', 'label' => 'Document Reference', 'required' => false, 'kind' => 'text'],
            ],
        ],
        'personnel' => [
            'label' => 'Technical Personnel',
            'sheet' => null,
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'required' => true, 'kind' => 'text'],
                ['key' => 'position', 'label' => 'Position', 'required' => false, 'kind' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'required' => false, 'kind' => 'text'],
            ],
        ],
    ];

    /**
     * Inspect an uploaded workbook: sheet list + a preview (detected header
     * row, sample columns/rows) of the default sheet for the given table.
     * Throws on an unreadable file - the caller surfaces that as a
     * validation error on the file input.
     */
    public function analyze(string $path, string $tableKey): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            $scan = $this->scanCsv($path);
            // Physical line count - buildPreview() derives the data-row count
            // from this minus the detected header row, exactly like xlsx.
            $totalRows = $this->countCsvLines($path);

            return [
                'ext' => 'csv',
                'sheets' => [['name' => 'CSV', 'totalRows' => $totalRows, 'totalColumns' => $scan['totalColumns']]],
                'preview' => $this->buildPreview('CSV', $scan['rows'], $totalRows, $scan['totalColumns'], null, null),
            ];
        }

        $reader = $this->reader($path);
        $info = collect($reader->listWorksheetInfo($path))->keyBy('worksheetName');
        $names = $info->keys()->all();

        if ($names === []) {
            throw new \RuntimeException('The workbook contains no sheets.');
        }

        $preferred = self::TARGETS[$tableKey]['sheet'] ?? null;
        $sheetName = (is_string($preferred) && $info->has($preferred)) ? $preferred : $names[0];

        $rows = $this->scanSheet($reader, $path, $sheetName);

        return [
            'ext' => 'xlsx',
            'sheets' => $info
                ->map(fn (array $meta, string $name): array => [
                    'name' => $name,
                    'totalRows' => (int) ($meta['totalRows'] ?? 1),
                    'totalColumns' => (int) ($meta['totalColumns'] ?? 1),
                ])
                ->values()
                ->all(),
            'preview' => $this->buildPreview(
                $sheetName,
                $rows,
                (int) ($info[$sheetName]['totalRows'] ?? 1),
                (int) ($info[$sheetName]['totalColumns'] ?? 1),
            ),
        ];
    }

    /**
     * Re-preview a (possibly different) sheet or row span for the mapping UI.
     * The header row is clamped into the scanned window; data start defaults
     * to header + 1 and can never climb back above it.
     */
    public function previewSheet(string $path, string $sheetName, ?int $headerRow = null, ?int $dataStart = null): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            $scan = $this->scanCsv($path);
            $totalRows = $this->countCsvLines($path);

            return $this->buildPreview('CSV', $scan['rows'], $totalRows, $scan['totalColumns'], $headerRow, $dataStart);
        }

        $reader = $this->reader($path);

        if (! in_array($sheetName, $reader->listWorksheetNames($path), true)) {
            throw new \RuntimeException('Sheet was not found in the workbook.');
        }

        $info = collect($reader->listWorksheetInfo($path))->firstWhere('worksheetName', $sheetName) ?? [];
        $rows = $this->scanSheet($reader, $path, $sheetName);

        return $this->buildPreview(
            $sheetName,
            $rows,
            (int) ($info['totalRows'] ?? 1),
            (int) ($info['totalColumns'] ?? 1),
            $headerRow,
            $dataStart,
        );
    }

    /**
     * Last mapping the user committed for this table, re-anchored to the
     * columns that exist in the current workbook. Entries pointing at a
     * column the current file does not have are dropped; everything else is
     * pre-filled so repeat imports of the same template start mostly done.
     *
     * @param  array<int, array{letter: string, label: string|null}>  $columns
     * @return array<string, string> target key => column letter ('' when unmapped)
     */
    public function recallMapping(string $tableKey, array $columns): array
    {
        $stored = session()->get('import-mapping:'.$tableKey, []);
        $validLetters = collect($columns)->pluck('letter')->flip();

        $recalled = collect($stored)
            ->filter(fn ($letter, $target): bool => is_string($letter)
                && $letter !== ''
                && $this->knownTarget($tableKey, (string) $target)
                && $validLetters->has($letter))
            ->all();

        return $recalled + $this->blankMapping($tableKey);
    }

    /** Persist the mapping the user just imported with (scoped per table). */
    public function rememberMapping(string $tableKey, array $mapping): void
    {
        session()->put('import-mapping:'.$tableKey, $mapping);
    }

    /** A ready-to-bind map of every target field for this table => ''. */
    public function blankMapping(string $tableKey): array
    {
        return collect(self::TARGETS[$tableKey]['fields'] ?? [])
            ->mapWithKeys(fn (array $field): array => [$field['key'] => ''])
            ->all();
    }

    public function knownTarget(string $tableKey, string $key): bool
    {
        return collect(self::TARGETS[$tableKey]['fields'] ?? [])
            ->contains(fn (array $field): bool => $field['key'] === $key);
    }

    /**
     * Convert a user mapping (target => letter) into the header map the
     * chunk reader understands: source column position => canonical key.
     *
     * @param  array<string, string>  $mapping
     * @return array<int, string> position-indexed canonical keys
     */
    public function buildHeaderMap(array $mapping): array
    {
        $headers = [];

        foreach ($mapping as $target => $letter) {
            $letter = strtoupper(trim((string) $letter));

            if ($letter === '' || preg_match('/^[A-Z]{1,3}$/', $letter) !== 1) {
                continue;
            }

            $headers[Coordinate::columnIndexFromString($letter) - 1] = (string) $target;
        }

        ksort($headers);

        return $headers;
    }

    /**
     * Header-row auto-detection: within the first 20 physical rows, the row
     * with the most non-empty cells wins (first max on ties). Exported
     * sheets carry title/junk banners above the header - banner rows are
     * sparse, the header row is the densest.
     *
     * @param  array<int|string, array<int|string, mixed>>  $rows
     */
    public function detectHeaderRow(array $rows, int $totalRows): int
    {
        $limit = min(self::SCAN_ROWS, max(1, $totalRows));
        $best = 1;
        $bestScore = -1;

        foreach ($rows as $rowNumber => $cells) {
            if (! is_int($rowNumber) || $rowNumber < 1 || $rowNumber > $limit) {
                continue;
            }

            $score = count(array_filter($cells, fn ($value): bool => $value !== null && trim((string) $value) !== ''));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $rowNumber;
            }
        }

        return $best;
    }

    /**
     * Shape the preview payload consumed by the mapping UI: one entry per
     * source column (letter, header label, up to 3 sample values) plus the
     * first few data rows for the spreadsheet-style preview grid.
     *
     * @param  array<int|string, array<int|string, mixed>>  $rows
     */
    private function buildPreview(string $sheetName, array $rows, int $totalRows, int $totalColumns, ?int $headerRow = null, ?int $dataStart = null): array
    {
        $headerRow = $headerRow ?? $this->detectHeaderRow($rows, $totalRows);
        $headerRow = min(max(1, $headerRow), max(1, $totalRows));
        $dataStart = $dataStart === null
            ? $headerRow + 1
            : min(max($headerRow + 1, $dataStart), max($headerRow + 1, $totalRows));

        $maxColumn = max(1, min($totalColumns, 120));
        $headerCells = $rows[$headerRow] ?? [];

        $columns = [];
        for ($index = 1; $index <= $maxColumn; $index++) {
            $letter = Coordinate::stringFromColumnIndex($index);
            $label = trim((string) ($headerCells[$letter] ?? ''));

            $samples = [];
            for ($rowNumber = $dataStart; $rowNumber < $dataStart + 3; $rowNumber++) {
                $value = $rows[$rowNumber][$letter] ?? null;
                $samples[] = $value === null ? '' : trim((string) $value);
            }

            $columns[] = [
                'letter' => $letter,
                'label' => $label !== '' ? $label : null,
                'samples' => $samples,
            ];
        }

        $sampleRows = [];
        for ($rowNumber = $dataStart; $rowNumber < $dataStart + 4; $rowNumber++) {
            if (! isset($rows[$rowNumber])) {
                continue;
            }

            $sampleRows[] = ['rowNumber' => $rowNumber, 'cells' => $rows[$rowNumber]];
        }

        return [
            'sheet' => $sheetName,
            'headerRow' => $headerRow,
            'dataStart' => $dataStart,
            'totalRows' => max(0, $totalRows - $headerRow),
            'totalColumns' => $totalColumns,
            'columns' => $columns,
            'sampleRows' => $sampleRows,
        ];
    }

    /**
     * Load the first SCAN_ROWS physical rows of a sheet (values only).
     * Returns physical-row-number keyed maps of letter => raw value.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanSheet(IReader $reader, string $path, string $sheetName): array
    {
        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new ChunkReadFilter(1, self::SCAN_ROWS));
        $workbook = $reader->load($path);
        $sheet = $workbook->getSheetByName($sheetName);

        if (! $sheet) {
            throw new \RuntimeException('Sheet could not be read.');
        }

        $rows = $sheet->toArray(null, true, true, true);
        $workbook->disconnectWorksheets();
        unset($sheet, $workbook);

        return array_slice($rows, 0, self::SCAN_ROWS, true);
    }

    /**
     * First SCAN_ROWS lines of a CSV file as letter-keyed cell maps, plus the
     * widest physical column count seen (header and data rows may disagree).
     *
     * @return array{rows: array<int, array<string, mixed>>, totalColumns: int}
     */
    private function scanCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        $rows = [];
        $maxColumns = 0;
        $line = 0;

        while (($row = fgetcsv($handle)) !== false && $line < self::SCAN_ROWS) {
            $line++;
            $maxColumns = max($maxColumns, count($row));
            $rows[$line] = $row;
        }

        fclose($handle);

        // Normalize list rows to the same letter-keyed shape the xlsx path
        // produces, so buildPreview() can address cells by letter uniformly.
        foreach ($rows as $line => $row) {
            $padded = array_pad($row, $maxColumns, null);
            $rows[$line] = [];
            foreach ($padded as $index => $value) {
                $rows[$line][Coordinate::stringFromColumnIndex($index + 1)] = $value;
            }
        }

        return ['rows' => $rows, 'totalColumns' => max(1, $maxColumns)];
    }

    private function countCsvLines(string $path): int
    {
        $lines = 0;
        $handle = fopen($path, 'rb');

        while (fgetcsv($handle) !== false) {
            $lines++;
        }

        fclose($handle);

        return $lines;
    }

    private function reader(string $path): IReader
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader;
    }
}
