<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
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
        // Loading the full workbook through PhpSpreadsheet is the single
        // most memory-hungry step of the wizard — on shared hosting the
        // default memory_limit (128M) kills PHP here with a fatal, which
        // surfaces as a bare 500 on /livewire/update. Lift both limits per
        // request, as far as the host allows (silently no-ops otherwise).
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // The raise above is a silent no-op when the host locks memory_limit
        // (.user.ini = PHP_INI_PERDIR / FPM php_admin_value) — log the
        // EFFECTIVE limit so a fatal that follows is explainable, and warn
        // when the workbook may not fit (the uncatchable OOM behind the
        // persistent live 500).
        $effectiveLimit = ini_get('memory_limit');
        if ($this->memoryLimitBytes($effectiveLimit) < 256 * 1024 * 1024) {
            Log::warning('import.analyze.memoryLimitLocked', [
                'effective_limit' => $effectiveLimit,
                'required_hint' => '256M+',
                'file' => basename($path),
            ]);
        }

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

    /**
     * Recall the last committed mapping for ANY target field set (managed or
     * dynamic tables): only entries whose target key still exists and whose
     * source column still exists in this workbook survive.
     *
     * @param  array<int, array{key: string}>  $fields
     * @param  array<int, array{letter: string}>  $columns
     * @return array<string, string>
     */
    public function recallFor(array $fields, string $tableKey, array $columns): array
    {
        $stored = session()->get('import-mapping:'.$tableKey, []);
        $validLetters = collect($columns)->pluck('letter')->flip();
        $known = collect($fields)->pluck('key')->flip();

        return collect($stored)
            ->filter(fn ($letter, $target): bool => is_string($letter)
                && $letter !== ''
                && $known->has((string) $target)
                && $validLetters->has($letter))
            ->all();
    }

    /**
     * Auto-map source columns to target fields by header label similarity.
     *
     * Normalized exact match wins, then substring containment, then a small
     * edit distance / similarity percentage. Each source column is used at
     * most once, so a suggestion is always a legal mapping; unmatched fields
     * are omitted (the UI leaves them blank for the user).
     *
     * @param  array<int, array{key: string, label: string}>  $fields
     * @param  array<int, array{letter: string, label: string|null}>  $columns
     * @return array<string, string> target key => column letter
     */
    public function suggestMapping(array $fields, array $columns): array
    {
        $normalizedColumns = collect($columns)
            ->map(fn (array $column): array => [
                'letter' => (string) $column['letter'],
                'norm' => $this->normalizeLabel((string) ($column['label'] ?? '')),
            ])
            ->filter(fn (array $column): bool => $column['norm'] !== '')
            ->values()
            ->all();

        $suggested = [];
        $usedLetters = [];

        foreach ($fields as $field) {
            $target = $this->normalizeLabel((string) ($field['label'] ?? ''));
            $key = $this->normalizeLabel((string) ($field['key'] ?? ''));

            if ($target === '' && $key === '') {
                continue;
            }

            $bestLetter = null;
            $bestScore = 0;

            foreach ($normalizedColumns as $column) {
                if (in_array($column['letter'], $usedLetters, true)) {
                    continue;
                }

                $score = $this->similarityScore($column['norm'], $target, $key);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestLetter = $column['letter'];
                }
            }

            if ($bestLetter !== null && $bestScore >= 60) {
                $suggested[(string) $field['key']] = $bestLetter;
                $usedLetters[] = $bestLetter;
            }
        }

        return $suggested;
    }

    private function similarityScore(string $column, string $target, string $key): int
    {
        if ($column === $target || ($key !== '' && $column === $key)) {
            return 100;
        }

        if ($target !== '' && (str_contains($column, $target) || str_contains($target, $column))) {
            return 70 + min(20, strlen($column));
        }

        if ($target !== '') {
            $distance = levenshtein($column, $target);

            if ($distance <= 1) {
                return 65;
            }

            if ($distance === 2) {
                return 60;
            }

            similar_text($column, $target, $percent);

            if ($percent >= 85) {
                return 62;
            }
        }

        return 0;
    }

    private function normalizeLabel(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/[^a-z0-9]+/', ' ', $label) ?? '';

        return trim(preg_replace('/\s+/', ' ', $label) ?? '');
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

        // Raw top rows for the "What is your first row?" picker — physical
        // rows 1–12 regardless of the detected header, so title/junk rows
        // above the header stay visible and clickable.
        $topRows = [];
        for ($rowNumber = 1; $rowNumber <= 12; $rowNumber++) {
            if (! isset($rows[$rowNumber])) {
                continue;
            }

            $topRows[] = ['rowNumber' => $rowNumber, 'cells' => $rows[$rowNumber]];
        }

        return [
            'sheet' => $sheetName,
            'headerRow' => $headerRow,
            'dataStart' => $dataStart,
            'totalRows' => max(0, $totalRows - $headerRow),
            'totalColumns' => $totalColumns,
            'columns' => $columns,
            'sampleRows' => $sampleRows,
            'topRows' => $topRows,
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

        // calculateFormulas MUST stay false: PhpSpreadsheet's calculation
        // engine enumerates every cell reference in a formula's range, and a
        // full-column formula (e.g. SUM(A:A) = 1,048,576 refs) allocates
        // ~33 MB in one go — the uncatchable OOM that surfaced as a bare 500
        // on POST /livewire/update on hosts where memory_limit is locked
        // below 512M. Google Sheets exports (e.g. =IFERROR(__xludf.DUMMYFUNCTION("QUERY(...)")))
        // carry bounded ranges that allocate 30-90 MB in a single array.
        // We only need the workbook's cached values for preview, so
        // oldCalculatedValue=true resolves formula cells from the file cache.
        $rows = $sheet->toArray(null, false, true, true, oldCalculatedValue: true);
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
        // Skip empty cells: system exports carry formatting (and the reader
        // filter bounds) across thousands of phantom columns — instantiating
        // their empty cells explodes memory during analysis.
        $reader->setReadEmptyCells(false);

        return $reader;
    }

    /**
     * Parse a php.ini shorthand memory value (128M, 1G, -1, bytes) to bytes.
     */
    private function memoryLimitBytes(string|false $value): int
    {
        if ($value === false || $value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $value = trim((string) $value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
