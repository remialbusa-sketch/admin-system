<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\HistoricalTsmsReport;
use App\Models\ImportBatch;
use App\Models\ImportFailure;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class SourceWorkbookImportService
{
    public const PRODUCT_SOURCE = 'product_database';

    public const EXECUTIVE_SOURCE = 'executive_dashboard';

    public const HISTORICAL_SOURCE = 'historical_tsms';

    public const PERSONNEL_SOURCE = 'personnel_list';

    public function importProductDatabase(string $path, ?int $userId = null): ImportBatch
    {
        $batch = $this->startBatch(self::PRODUCT_SOURCE, basename($path), 'PDB Data', $path, $userId);
        $reader = $this->reader($path);
        $sheetName = $this->worksheetName($reader, $path, 'PDB Data');
        if (! $sheetName) {
            return $this->failBatch($batch, 'PDB Data sheet was not found.');
        }

        // The "PDB Data" sheet is the computed QUERY of the raw "PDB" sheet, but
        // its QUERY only selects columns up to AG — it omits "PMS FREQUENCY" (AH)
        // and "TSP IN-CHARGE" (AI). Those two live on the raw "PDB" sheet, whose
        // header row is 8 and data starts at row 9. PDB row N maps to PDB Data
        // row N-7. Load them once and merge by row number during the chunk loop.
        $pdbExtra = $this->pdbExtraColumns($path);

        $headers = $this->headersForSheet($reader, $path, $sheetName);
        $this->prepareBatch($batch, $reader, $path, $sheetName);
        $this->processChunks($reader, $path, $sheetName, $headers, function (array $data, int $rowNumber) use ($batch, $pdbExtra): void {
            $this->upsertProductRow($data, $rowNumber, $batch, $pdbExtra[$rowNumber + 7] ?? []);
        }, $batch);

        $this->dedupeProductRows($batch);

        return $this->completeBatch($batch);
    }

    /**
     * One PDB row -> Account + Installation upsert (shared by the classic
     * auto-import and the mapped import wizard). $extra carries the raw "PDB"
     * sheet's pms_frequency / tsp_in_charge during auto-import; the mapped
     * wizard passes none, keeping those two fields manual-only.
     */
    private function upsertProductRow(array $data, int $rowNumber, ImportBatch $batch, array $extra = []): void
    {
        $data['pms_frequency'] = $extra['pms_frequency'] ?? '';
        $data['tsp_in_charge'] = $extra['tsp_in_charge'] ?? '';

        $customerName = $this->value($data, 'customer_name');
        $deviceDescription = $this->value($data, 'device_description');

        if ($customerName === '' && $deviceDescription === '') {
            // Not a real product record - typically a leftover dragged-formula
            // row past the real data range (e.g. stray "#N/A" / "!" values with
            // no customer or device identified). Skip it rather than creating a
            // blank/ghost installation.
            return;
        }

        // Compute the stable source id / hash from the ORIGINAL row data
        // (without the injected pms_frequency / tsp_in_charge extras) so
        // re-imports match existing records instead of creating duplicates.
        $baseData = $data;
        unset($baseData['pms_frequency'], $baseData['tsp_in_charge']);
        $sourceId = $this->sourceId($data['no'] ?? null, $baseData, 'product');
        $hash = $this->rowHash($baseData);
        $accountKey = $this->value($data, 'customer_name') ?: 'unknown-account-'.substr($hash, 0, 16);

        $account = Account::updateOrCreate(
            ['source_system' => self::PRODUCT_SOURCE, 'source_record_id' => $accountKey],
            [
                'import_batch_id' => $batch->id,
                'source_hash' => hash('sha256', $accountKey.'|'.$this->value($data, 'customer_address').'|'.$this->value($data, 'branch')),
                'customer_name' => $this->value($data, 'customer_name'),
                'customer_address' => $this->value($data, 'customer_address'),
                'hospital_section' => $this->value($data, 'hospital_section'),
                'branch' => $this->value($data, 'branch'),
                'region' => $this->resolveRegion($data, ['branch', 'region']),
                'raw_data' => $data,
            ],
        );

        Installation::updateOrCreate(
            ['source_system' => self::PRODUCT_SOURCE, 'source_record_id' => $sourceId],
            [
                'account_id' => $account->id,
                'import_batch_id' => $batch->id,
                'source_hash' => $hash,
                'device_description' => $this->value($data, 'device_description'),
                'brand' => $this->value($data, 'brand'),
                'machine_type' => $this->value($data, 'machine_type'),
                'serial_number' => $this->value($data, 'serial_number'),
                'bu_no' => $this->value($data, 'bu_no'),
                'equipment_type' => $this->value($data, 'system_type'),
                'installation_date' => $this->date($this->value($data, 'installation_date')),
                'uninstallation_date' => $this->date($this->value($data, 'pulled_out_date')),
                'device_status' => $this->value($data, 'device_status'),
                'device_ownership' => $this->value($data, 'device_ownership'),
                'deal_type' => $this->value($data, 'deal_type'),
                'charge_to' => $this->value($data, 'charge_to'),
                'warranty_status' => $this->value($data, 'warranty_status'),
                'warranty_period_years' => $this->number($this->value($data, 'warranty_period')),
                'warranty_end_date' => $this->date($this->value($data, 'warranty_date_end')),
                'service_contract_status' => $this->value($data, 'service_contract_status'),
                'service_contract_amount' => $this->number($this->value($data, 'service_contract_amount')),
                'service_contract_start' => $this->date($this->value($data, 'service_contract_start')),
                'service_contract_end' => $this->date($this->value($data, 'service_contract_end')),
                'annual_bu_charge' => $this->number($this->value($data, 'annual_bu_charge')),
                'pms_frequency' => $this->nullableValue($data, 'pms_frequency'),
                'tsp_in_charge' => $this->nullableValue($data, 'tsp_in_charge'),
                'raw_data' => $data,
            ],
        );
    }

    public function importServiceRequests(string $path, ?int $userId = null): ImportBatch
    {
        $batch = $this->startBatch(self::EXECUTIVE_SOURCE, basename($path), 'Service Requests', $path, $userId);
        $reader = $this->reader($path);
        $sheetName = $this->worksheetName($reader, $path, 'Service Requests');
        if (! $sheetName) {
            return $this->failBatch($batch, 'Service Requests sheet was not found.');
        }

        $headers = $this->headersForSheet($reader, $path, $sheetName);
        $this->prepareBatch($batch, $reader, $path, $sheetName);
        $this->processChunks($reader, $path, $sheetName, $headers, function (array $data, int $rowNumber) use ($batch): void {
            $this->upsertServiceRequestRow($data, $rowNumber, $batch);
        }, $batch);

        return $this->completeBatch($batch);
    }

    /** One Service Requests row -> ServiceRequest upsert (shared by both import paths). */
    private function upsertServiceRequestRow(array $data, int $rowNumber, ImportBatch $batch): void
    {
        $requestId = $this->value($data, 'service_request_no') ?: $this->value($data, 'service_request');
        // The actual file row number is the stable fallback identity
        // (counter-based ids drifted once counters were batched).
        $requestId = $requestId ?: 'row-'.$rowNumber;

        ServiceRequest::updateOrCreate(
            ['source_system' => self::EXECUTIVE_SOURCE, 'source_record_id' => $requestId],
            [
                'import_batch_id' => $batch->id,
                'source_hash' => $this->rowHash($data),
                'source_updated_at' => $this->dateTime($this->value($data, 'date_created')),
                'service_request_number' => $this->value($data, 'service_request_no'),
                'service_request_code' => $this->value($data, 'service_request'),
                'customer_name' => $this->value($data, 'customer_name'),
                'ticket_status' => $this->value($data, 'ticket_status'),
                'group_status' => $this->normalizeStatus($this->value($data, 'group')),
                'branch' => $this->value($data, 'branch'),
                'tsp_assignment' => $this->value($data, 'tsp_assigned') ?: $this->value($data, 'reassigned_tsp'),
                'coordinator' => $this->value($data, 'coordinator'),
                'requesting_entity' => $this->value($data, 'requesting_entity'),
                'requestor_name' => $this->value($data, 'requestors_name'),
                'requestor_email' => $this->value($data, 'requestor_s_email'),
                'requestor_phone' => $this->value($data, 'phone_number'),
                'region' => $this->resolveRegion($data, ['branch', 'regions', 'assign_region']),
                'department' => $this->value($data, 'department'),
                'contract_type' => $this->value($data, 'contract_type'),
                'request_type' => $this->value($data, 'type_of_request'),
                'service_type' => $this->value($data, 'service_type'),
                'brand' => $this->value($data, 'brand'),
                'machine_type' => $this->value($data, 'machine_type'),
                'serial_number' => $this->value($data, 'serial_no'),
                'concerns' => $this->value($data, 'concerns'),
                'date_needed' => $this->date($this->value($data, 'date_needed')),
                'service_indicator' => $this->value($data, 'service_indicator'),
                'device_ownership' => $this->value($data, 'device_ownership'),
                'raw_data' => $data,
            ],
        );
    }

    public function importTechnicalReports(string $path, ?int $userId = null): ImportBatch
    {
        $batch = $this->startBatch(self::EXECUTIVE_SOURCE, basename($path), 'Technical Reports', $path, $userId);
        $reader = $this->reader($path);
        $sheetName = $this->worksheetName($reader, $path, 'Technical Reports');
        if (! $sheetName) {
            return $this->failBatch($batch, 'Technical Reports sheet was not found.');
        }

        $headers = $this->headersForSheet($reader, $path, $sheetName);
        $this->prepareBatch($batch, $reader, $path, $sheetName);
        $this->processChunks($reader, $path, $sheetName, $headers, function (array $data, int $rowNumber) use ($batch): void {
            $this->upsertTechnicalReportRow($data, $rowNumber, $batch);
        }, $batch);

        return $this->completeBatch($batch);
    }

    /** One Technical Reports row -> TechnicalReport upsert (shared by both import paths). */
    private function upsertTechnicalReportRow(array $data, int $rowNumber, ImportBatch $batch): void
    {
        $reference = $this->value($data, 'reference_number');
        if ($reference === '') {
            throw new \InvalidArgumentException('Reference Number is required.');
        }

        $requestNumber = $this->value($data, 'service_request_number');
        $request = $requestNumber === '' ? null : ServiceRequest::where('source_system', self::EXECUTIVE_SOURCE)
            ->where(function ($query) use ($requestNumber): void {
                $query->where('service_request_number', $requestNumber)->orWhere('service_request_code', $requestNumber);
            })->first();

        TechnicalReport::updateOrCreate(
            ['source_system' => self::EXECUTIVE_SOURCE, 'source_record_id' => $reference],
            [
                'service_request_id' => $request?->id,
                'import_batch_id' => $batch->id,
                'source_hash' => $this->rowHash($data),
                'reference_number' => $reference,
                'source_updated_at' => $this->dateTime($this->value($data, 'date_created')),
                'service_request_number' => $requestNumber,
                'report_name' => $this->value($data, 'name'),
                'ticket_status' => $this->value($data, 'ticket_status'),
                'service_status' => $this->value($data, 'service_status'),
                'group_status' => $this->normalizeStatus($this->value($data, 'service_status') ?: $this->value($data, 'ticket_status')),
                'customer_name' => $this->value($data, 'customer_name_sr'),
                'service_started_at' => $this->dateTime($this->value($data, 'service_start_date_time')),
                'service_completed_at' => $this->dateTime($this->value($data, 'service_end_date_time')),
                'tsp_name' => $this->value($data, 'tsp_name') ?: $this->value($data, 'tsp'),
                'tsp_display_name' => $this->value($data, 'tsp') ?: $this->value($data, 'tsp_name'),
                'brand' => $this->value($data, 'brand'),
                'machine_type' => $this->value($data, 'machine_type'),
                'job_done' => $this->value($data, 'job_done'),
                'parts_replaced' => $this->value($data, 'parts_replaced'),
                'recommendation' => $this->value($data, 'recommendation'),
                'repair_time_hours' => $this->number($this->value($data, 'repair_time_hours')),
                'response_time_hours' => $this->number($this->value($data, 'response_time')),
                'report_url' => $this->value($data, 'report_copy_testing') ?: $this->value($data, 'files'),
                'raw_data' => $data,
            ],
        );
    }

    public function importHistoricalTsms(string $path, ?int $userId = null): ImportBatch
    {
        $batch = $this->startBatch(self::HISTORICAL_SOURCE, basename($path), 'MCBTSi TSMS', $path, $userId);
        $reader = $this->reader($path);
        $sheetName = $this->worksheetName($reader, $path, 'MCBTSi TSMS');
        if (! $sheetName) {
            return $this->failBatch($batch, 'MCBTSi TSMS sheet was not found.');
        }

        $headers = $this->headersForSheet($reader, $path, $sheetName);
        $this->prepareBatch($batch, $reader, $path, $sheetName);
        $this->processChunks($reader, $path, $sheetName, $headers, function (array $data, int $rowNumber) use ($batch): void {
            $this->upsertHistoricalRow($data, $rowNumber, $batch);
        }, $batch);

        return $this->completeBatch($batch);
    }

    /** One historical TSMS row -> HistoricalTsmsReport upsert (shared by both import paths). */
    private function upsertHistoricalRow(array $data, int $rowNumber, ImportBatch $batch): void
    {
        $timestamp = $this->value($data, 'timestamp');
        $sourceId = $timestamp !== '' ? $timestamp.'-row-'.$rowNumber : 'row-'.$rowNumber;

        HistoricalTsmsReport::updateOrCreate(
            ['source_system' => self::HISTORICAL_SOURCE, 'source_record_id' => $sourceId],
            [
                'import_batch_id' => $batch->id,
                'source_hash' => $this->rowHash($data),
                'source_updated_at' => $this->dateTime($timestamp),
                'response_timestamp' => $this->dateTime($timestamp),
                'csr_number' => $this->value($data, 'csr'),
                'problem_or_complaint' => $this->value($data, 'problem_complaints'),
                'brand' => $this->value($data, 'brand'),
                'model' => $this->value($data, 'model'),
                'serial_number' => $this->value($data, 'serial_no'),
                'account_name' => $this->value($data, 'account_name'),
                'account_address' => $this->value($data, 'address'),
                'service_type' => $this->value($data, 'service_type'),
                'status' => $this->value($data, 'status'),
                'job_done' => $this->value($data, 'job_done'),
                'parts_replaced' => $this->value($data, 'parts_replaced'),
                'recommendation' => $this->value($data, 'recommendation_remarks'),
                'login_at' => $this->dateTime($this->value($data, 'log_in_date').' '.$this->value($data, 'login_time')),
                'service_at' => $this->dateTime($this->value($data, 'service_start_time')),
                'logout_at' => $this->dateTime($this->value($data, 'log_out_date').' '.$this->value($data, 'log_out_time')),
                'tsr_number' => $this->value($data, 'tsr'),
                'tsp_name' => $this->value($data, 'tsp_name'),
                'work_with_personnel' => $this->value($data, 'tsp_workwith'),
                'branch' => $this->value($data, 'branch'),
                'document_reference' => $this->value($data, 'document_reference_number') ?: $this->value($data, 'document_reference'),
                'raw_data' => $data,
            ],
        );
    }

    public function importPersonnel(string $path, ?int $userId = null): ImportBatch
    {
        $batch = $this->startBatch(self::PERSONNEL_SOURCE, basename($path), 'Personnel list', $path, $userId);

        $reader = $this->reader($path);
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            return $this->failBatch($batch, 'Personnel import expects an .xlsx workbook with a fixed header layout.');
        }

        // The fixed layout: header on physical row 5, data from row 6, with
        // the identity cells in C (name), D (position) and E (branch) -
        // expressed here as 0-based positions for the shared chunk reader.
        $sheetName = $reader->listWorksheetNames($path)[0] ?? '';
        if ($sheetName === '') {
            return $this->failBatch($batch, 'Personnel workbook contains no sheets.');
        }

        $this->prepareBatch($batch, $reader, $path, $sheetName, 6);
        $this->processChunks(
            $reader,
            $path,
            $sheetName,
            [2 => 'name', 3 => 'position', 4 => 'branch'],
            function (array $data) use ($batch): void {
                $this->upsertPersonnelRow($data, $batch);
            },
            $batch,
            6,
        );

        return $this->completeBatch($batch);
    }

    /**
     * One personnel row -> TechnicalPersonnel upsert (shared by both import
     * paths). Keyed on the name (the stable natural identity) - the old
     * whole-row hash minted a new row whenever position or branch changed,
     * orphaning the previous version.
     */
    private function upsertPersonnelRow(array $data, ImportBatch $batch): void
    {
        $name = $this->value($data, 'name');
        if ($name === '') {
            return;
        }

        $row = [
            'name' => $name,
            'position' => $this->value($data, 'position'),
            'branch' => $this->value($data, 'branch'),
        ];

        TechnicalPersonnel::updateOrCreate(
            ['source_system' => self::PERSONNEL_SOURCE, 'source_record_id' => 'personnel-'.Str::slug($row['name'])],
            [
                'import_batch_id' => $batch->id,
                'name' => $row['name'],
                'position' => $row['position'] ?: null,
                'branch' => $row['branch'] ?: null,
                'region' => $this->resolveRegion($row, ['branch']),
                'raw_data' => $row,
            ],
        );
    }

    /**
     * Mapped import wizard entry point: the user picked the sheet, the
     * header row, and hand-mapped app fields to source columns. The mapping
     * (target key => column letter) is compiled into a position-indexed
     * header map and pushed through the same chunk/upsert machinery as the
     * classic imports, so identity, failure tracking, batch bookkeeping and
     * cache invalidation behave identically.
     *
     * @param  array<string, string>  $mapping  target key => column letter
     */
    public function importMapped(
        string $path,
        string $tableKey,
        string $sheetName,
        array $mapping,
        int $headerRow,
        int $dataStart,
        ?int $userId = null,
    ): ImportBatch {
        [$sourceSystem, $sourceName, $handler] = match ($tableKey) {
            'installed-products' => [self::PRODUCT_SOURCE, 'PDB Data', 'upsertProductRow'],
            'service-requests' => [self::EXECUTIVE_SOURCE, 'Service Requests', 'upsertServiceRequestRow'],
            'technical-reports' => [self::EXECUTIVE_SOURCE, 'Technical Reports', 'upsertTechnicalReportRow'],
            'history-reports' => [self::HISTORICAL_SOURCE, 'MCBTSi TSMS', 'upsertHistoricalRow'],
            'personnel' => [self::PERSONNEL_SOURCE, 'Personnel list', 'upsertPersonnelRow'],
            default => throw new \InvalidArgumentException('Unsupported table for mapped import.'),
        };

        // Big real-world workbooks (8k+ rows) can outlive the default 30s
        // request window; row-level work runs inside retry() + transactions,
        // so lifting the time limit per request is safe.
        @set_time_limit(0);

        $headers = (new ImportMappingService)->buildHeaderMap($mapping);

        if ($headers === []) {
            throw new \InvalidArgumentException('The column mapping does not include any source columns.');
        }

        $batch = $this->startBatch($sourceSystem, $sourceName, $sheetName !== '' ? $sheetName : $sourceName, $path, $userId);
        $batch->metadata = array_merge($batch->metadata ?? [], [
            'mapped_import' => true,
            'target_table' => match ($tableKey) {
                'installed-products' => 'installations',
                'service-requests' => 'service_requests',
                'technical-reports' => 'technical_reports',
                'history-reports' => 'historical_tsms_reports',
                'personnel' => 'technical_personnel',
                default => null,
            },
            'header_row' => $headerRow,
            'data_start_row' => $dataStart,
            'mapping' => collect($mapping)
                ->filter(fn ($letter): bool => trim((string) $letter) !== '')
                ->map(fn ($letter): string => strtoupper(trim((string) $letter)))
                ->all(),
        ]);
        $batch->save();

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($tableKey === 'personnel' && $ext === 'csv') {
            return $this->failBatch($batch, 'Personnel import expects an .xlsx workbook with a fixed header layout.');
        }

        $reader = $this->reader($path);

        if ($ext !== 'csv' && ! in_array($sheetName, $reader->listWorksheetNames($path), true)) {
            return $this->failBatch($batch, 'The chosen sheet was not found in the workbook.');
        }

        $this->prepareBatch($batch, $reader, $path, $ext === 'csv' ? 'CSV' : $sheetName, $dataStart);

        $this->processChunks($reader, $path, $ext === 'csv' ? 'CSV' : $sheetName, $headers, function (array $data, int $rowNumber) use ($batch, $handler, $tableKey): void {
            if ($tableKey === 'personnel') {
                $this->upsertPersonnelRow($data, $batch);

                return;
            }

            $this->{$handler}($data, $rowNumber, $batch);
        }, $batch, $dataStart);

        if ($tableKey === 'installed-products') {
            $this->dedupeProductRows($batch);
        }

        return $this->completeBatch($batch);
    }

    /**
     * Single region resolver for all import paths: try each candidate column in
     * priority order and return the first canonical dashboard region, or null.
     * Keeps call sites uniform so fallback behavior can't drift between imports.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys  column names to try, in priority order
     */
    private function resolveRegion(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $candidate = $this->value($data, $key);
            if ($candidate === '') {
                continue;
            }
            $region = $this->regionForBranch($candidate);
            if ($region !== null) {
                return $region;
            }
        }

        return null;
    }

    /**
     * Canonicalize a branch or region string into one of the 4 dashboard regions:
     * ['NCR', 'North Luzon', 'Visayas', 'Mindanao'].
     * Handles service-request branch codes (NLR1/CEB/CDO/...), descriptive branch
     * names (Cebu, Ilo-Ilo, South Luzon) and raw region text ("Region III (Central Luzon)").
     * Per source convention, South Luzon / SL is classified as NCR.
     */
    public function regionForBranch(?string $branch): ?string
    {
        $raw = preg_replace('/[^a-z0-9]/', '', strtolower($branch ?? ''));
        if ($raw === '') {
            return null;
        }

        // Descriptive region names that directly name a dashboard bucket.
        // Also detect a "Region <N>" prefix (Arabic or Roman) by capturing the leading numeral token.
        $arabic = [
            '1' => 'North Luzon', '2' => 'North Luzon', '3' => 'North Luzon',
            '4' => 'NCR', '4a' => 'North Luzon', '4b' => 'North Luzon', '5' => 'NCR',
            '6' => 'Visayas', '7' => 'Visayas', '8' => 'Visayas',
            '9' => 'Mindanao', '10' => 'Mindanao', '11' => 'Mindanao', '12' => 'Mindanao', '13' => 'Mindanao',
        ];
        $romanNum = [
            'i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'iva' => 4, 'ivb' => 4, 'v' => 5,
            'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9, 'x' => 10, 'xi' => 11, 'xii' => 12, 'xiii' => 13,
        ];
        if (preg_match('/^region(4a|4b|iva|ivb|[0-9]+|[ivxl]+)/', $raw, $m)) {
            $n = $m[2] ?? $m[1];
            if (array_key_exists($n, $arabic)) {
                return $arabic[$n];
            }
            if (array_key_exists($n, $romanNum) && array_key_exists((string) $romanNum[$n], $arabic)) {
                return $arabic[(string) $romanNum[$n]];
            }
        }
        if (in_array($raw, ['northluzon', 'car', 'caran', 'carb'], true)) {
            return 'North Luzon';
        }
        if (in_array($raw, ['visayas', 'cebu', 'bacolod', 'iloilo', 'tacloban', 'region6', 'region7', 'region8',
            'regionvi', 'regionvii', 'regionviii',
        ], true)) {
            return 'Visayas';
        }
        if (in_array($raw, ['mindanao', 'davao', 'cdo', 'zamboanga', 'region9', 'region10', 'region11', 'region12',
            'region13', 'regionix', 'regionx', 'regionxi', 'regionxii', 'regionxiii', 'barmm', 'caraga',
        ], true)) {
            return 'Mindanao';
        }
        if (in_array($raw, [
            'sl', 'southluzon', 'southernluzon', 'region4', 'region5', 'regioniv', 'regionv', 'region4a',
            'calabarzon', 'bicol',
        ], true)) {
            return 'NCR';
        }

        // Branch codes seen in Service Requests.
        if (in_array($raw, ['nlr1', 'nlr2', 'nlr3'], true)) {
            return 'North Luzon';
        }
        if (in_array($raw, ['ceb', 'bac', 'ilo', 'tac'], true)) {
            return 'Visayas';
        }
        if (in_array($raw, ['cdo', 'dav', 'zam'], true)) {
            return 'Mindanao';
        }

        return $raw === 'ncr' ? 'NCR' : null;
    }

    /**
     * Map a raw status token to a single canonical value.
     * Canonical set: 'Completed', 'In-Progress', 'Open', 'Rejected', 'For Continuation', 'For Escalation', null.
     * Normalizes case, hyphen/space variants and strips multi-value noise.
     */
    public function normalizeStatus(?string $status): ?string
    {
        if ($status === null || trim($status) === '') {
            return null;
        }
        $tok = trim(mb_strtolower($status));
        if (str_contains($tok, ',')) {
            $tok = explode(',', $tok)[0];
        }
        $tok = trim(preg_replace('/\s+/', '-', $tok));

        return match ($tok) {
            'completed', 'resolved', 'closed', 'served', 'done' => 'Completed',
            'in-progress', 'in_progress', 'inprogress', 'pending', 'in-school' => 'In-Progress',
            'open', 'new', 'unassigned' => 'Open',
            'rejected', 'cancelled', 'canceled', 'void' => 'Rejected',
            'for-continuation', 'forcontinuation' => 'For Continuation',
            'for-escalation', 'forescalation', 'escalated' => 'For Escalation',
            default => null,
        };
    }

    private function startBatch(string $sourceSystem, string $sourceName, string $sheet, string $path, ?int $userId): ImportBatch
    {
        return ImportBatch::create([
            'source_system' => $sourceSystem,
            'source_name' => $sourceName,
            'source_sheet' => $sheet,
            'source_path' => $path,
            'status' => 'pending',
            'metadata' => [
                'target_table' => match ($sheet) {
                    'Service Requests' => 'service_requests',
                    'Technical Reports' => 'technical_reports',
                    'PDB Data' => 'installations',
                    'MCBTSi TSMS' => 'historical_tsms_reports',
                    'Personnel list' => 'technical_personnel',
                    default => null,
                },
                'import_mode' => 'upsert',
                'raw_row_preserved' => true,
                'source_file_sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            ],
            'run_by' => $userId,
        ]);
    }

    private function reader(string $path): IReader
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        return $reader;
    }

    /**
     * Read the "PMS FREQUENCY" (AH) and "TSP IN-CHARGE" (AI) columns from the raw
     * "PDB" sheet. These are NOT present in the "PDB Data" sheet (its QUERY stops
     * at column AG). The PDB sheet header is row 8 and data starts at row 9, so
     * the returned map is keyed by PDB row number.
     *
     * @return array<int, array{pms_frequency: string, tsp_in_charge: string}>
     */
    private function pdbExtraColumns(string $path): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            return [];
        }

        try {
            $reader = $this->reader($path);
            $reader->setLoadSheetsOnly(['PDB']);
            $workbook = $reader->load($path);
            $sheet = $workbook->getSheetByName('PDB');

            if (! $sheet) {
                return [];
            }

            $map = [];
            $highestRow = $sheet->getHighestRow();

            for ($row = 9; $row <= $highestRow; $row++) {
                $pms = $sheet->getCell('AH'.$row)->getValue();
                $tsp = $sheet->getCell('AI'.$row)->getValue();
                $map[$row] = [
                    'pms_frequency' => $this->value(['pms_frequency' => $pms], 'pms_frequency'),
                    'tsp_in_charge' => $this->value(['tsp_in_charge' => $tsp], 'tsp_in_charge'),
                ];
            }

            $workbook->disconnectWorksheets();
            unset($sheet, $workbook);
            gc_collect_cycles();

            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    private function worksheetName(IReader $reader, string $path, string $preferredName): ?string
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            return 'CSV';
        }

        $names = $reader->listWorksheetNames($path);

        if (in_array($preferredName, $names, true)) {
            return $preferredName;
        }

        return count($names) === 1 ? $names[0] : null;
    }

    private function headersForSheet(IReader $reader, string $path, string $sheetName): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            $handle = fopen($path, 'rb');
            $headers = fgetcsv($handle) ?: [];
            fclose($handle);

            return $this->normalizeHeaders($headers);
        }

        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new ChunkReadFilter(1, 1));
        $workbook = $reader->load($path);
        $headers = $this->headers($workbook->getSheetByName($sheetName));
        $workbook->disconnectWorksheets();
        unset($workbook);

        return $headers;
    }

    private function prepareBatch(ImportBatch $batch, IReader $reader, string $path, string $sheetName, int $dataStart = 2): void
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            $lines = 0;
            $handle = fopen($path, 'rb');
            while (fgetcsv($handle) !== false) {
                $lines++;
            }
            fclose($handle);

            $batch->update([
                'total_rows' => max(0, $lines - $dataStart + 1),
                'status' => 'processing',
                'started_at' => now(),
            ]);

            return;
        }

        $info = collect($reader->listWorksheetInfo($path))->firstWhere('worksheetName', $sheetName);
        $batch->update([
            'total_rows' => max(0, ((int) ($info['totalRows'] ?? 1)) - $dataStart + 1),
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    private function processChunks(IReader $reader, string $path, string $sheetName, array $headers, callable $callback, ImportBatch $batch, int $dataStart = 2): void
    {
        // Counters are flushed per chunk instead of per row (an 8k-row import
        // used to issue one UPDATE per row just for bookkeeping).
        $ok = 0;
        $failed = 0;
        $flush = function () use ($batch, &$ok, &$failed): void {
            if ($ok > 0) {
                $batch->increment('processed_rows', $ok);
                $ok = 0;
            }
            if ($failed > 0) {
                $batch->increment('failed_rows', $failed);
                $failed = 0;
            }
        };

        // A structural failure (corrupt sheet, reader error) must leave the
        // batch as 'failed', never stuck in 'processing' forever.
        $failStructurally = function (Throwable $exception) use ($batch, $flush): never {
            $flush();
            $batch->update(['status' => 'failed', 'completed_at' => now()]);
            $batch->failures()->create([
                'error_type' => 'configuration',
                'error_message' => Str::limit($exception->getMessage(), 1000),
            ]);
            $this->invalidateExecutiveCaches($batch);
            throw $exception;
        };

        try {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
                $handle = fopen($path, 'rb');
                fgetcsv($handle); // header line
                // Mapped imports may place the header further down - skip any
                // banner rows between it and the first data row.
                for ($line = 2; $line < $dataStart; $line++) {
                    fgetcsv($handle);
                }
                $rowNumber = $dataStart - 1;
                $sinceFlush = 0;

                while (($row = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                        continue;
                    }

                    $this->runRow($batch, $rowNumber, $row, $callback, $headers) ? $ok++ : $failed++;
                    if (++$sinceFlush >= 250) {
                        $flush();
                        $sinceFlush = 0;
                    }
                }

                fclose($handle);
                $flush();

                return;
            }

            // $batch->total_rows counts data rows below dataStart, so the last
            // physical row is total_rows + dataStart - 1.
            $lastPhysicalRow = $batch->total_rows + $dataStart - 1;
            $chunkSize = 1000;

            for ($start = $dataStart; $start <= $lastPhysicalRow; $start += $chunkSize) {
                $end = min($start + $chunkSize - 1, $lastPhysicalRow);
                $reader->setLoadSheetsOnly([$sheetName]);
                $reader->setReadFilter(new ChunkReadFilter($start, $end));
                $workbook = $reader->load($path);
                $sheet = $workbook->getSheetByName($sheetName);

                if ($sheet) {
                    foreach ($sheet->toArray(null, true, true, true) as $rowNumber => $row) {
                        if ($rowNumber === 1 || $rowNumber < $dataStart || count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                            continue;
                        }

                        $this->runRow($batch, $rowNumber, $row, $callback, $headers) ? $ok++ : $failed++;
                    }
                }

                $flush();
                $workbook->disconnectWorksheets();
                unset($sheet, $workbook);
                gc_collect_cycles();
            }
        } catch (Throwable $exception) {
            $failStructurally($exception);
        }
    }

    private function completeBatch(ImportBatch $batch): ImportBatch
    {
        $batch->update([
            'status' => $batch->failed_rows > 0 ? 'completed_with_errors' : 'completed',
            'completed_at' => now(),
        ]);
        $this->invalidateExecutiveCaches($batch);

        return $batch->refresh();
    }

    private function failBatch(ImportBatch $batch, string $message): ImportBatch
    {
        $batch->update(['status' => 'failed', 'completed_at' => now()]);
        $batch->failures()->create(['error_type' => 'configuration', 'error_message' => $message]);
        $this->invalidateExecutiveCaches($batch);

        return $batch->refresh();
    }

    /**
     * The Home dashboard is a Product Database overview and Technical Service
     * Analysis is a Technical Reports overview — both cached. Product imports
     * drop the product keys; executive-dashboard imports (service requests +
     * technical reports) drop the TSA keys.
     */
    private function invalidateExecutiveCaches(?ImportBatch $batch): void
    {
        if ($batch?->source_system === self::PRODUCT_SOURCE) {
            $this->forgetProductSummaryCache();

            return;
        }

        if ($batch?->source_system === self::EXECUTIVE_SOURCE) {
            $this->forgetTsaSummaryCache();
        }
    }

    private function forgetProductSummaryCache(): void
    {
        foreach ([...ProductDashboardService::REGIONS, 'all'] as $scope) {
            foreach (ProductDashboardService::PERIODS as $months) {
                Cache::forget('product:summary:'.$scope.':'.$months);
            }
        }
    }

    private function forgetTsaSummaryCache(): void
    {
        foreach (TechnicalServiceAnalysisService::PERIODS as $days) {
            Cache::forget('tsa:summary:'.$days);
        }
    }

    /**
     * Historical PDB imports minted source ids from the whole-row hash, so any
     * changed cell produced a NEW source_record_id while the old version stayed
     * behind. The PDB Data sheet is a computed QUERY whose row numbers shift
     * between exports, so the collapse key is the same BUSINESS identity the
     * upsert now uses: (customer, serial number, device description). Within
     * such a group the newest row wins; rows with a different account,
     * description, or serial are distinct devices and are never touched. Fully
     * blank identity rows are skipped as a safety guard.
     */
    public function dedupeProductRows(?ImportBatch $batch = null): int
    {
        $rows = Installation::query()
            ->where('source_system', self::PRODUCT_SOURCE)
            ->with('account:id,customer_name')
            ->orderBy('id')
            ->get(['id', 'account_id', 'serial_number', 'device_description']);

        $groups = [];
        foreach ($rows as $row) {
            $customer = (string) $row->account?->customer_name;
            $serial = trim((string) $row->serial_number);
            $description = trim((string) $row->device_description);

            if ($customer === '' && $serial === '' && $description === '') {
                continue; // fully blank identity — never collapse
            }

            $groups[$customer.'|'.$serial.'|'.$description][] = $row->id;
        }

        $staleIds = [];
        foreach ($groups as $ids) {
            if (count($ids) > 1) {
                array_push($staleIds, ...array_slice($ids, 0, -1)); // keep newest (max id)
            }
        }

        if ($staleIds === []) {
            return 0;
        }

        $customColumnIds = CustomTableColumn::query()
            ->where('table_key', 'installed-products')
            ->pluck('id');
        CustomTableColumnValue::query()
            ->whereIn('custom_column_id', $customColumnIds)
            ->whereIn('row_id', $staleIds)
            ->delete();

        $removed = 0;
        foreach (array_chunk($staleIds, 500) as $chunk) {
            $removed += Installation::query()->whereIn('id', $chunk)->delete();
        }

        if ($batch !== null && $removed > 0) {
            $batch->metadata = array_merge($batch->metadata ?? [], ['deduped_rows' => $removed]);
            $batch->save();
        }

        if ($removed > 0) {
            $this->forgetProductSummaryCache();
        }

        return $removed;
    }

    /** Run one row in its own transaction; @return bool true on success. */
    private function runRow(ImportBatch $batch, int $rowNumber, array $row, callable $callback, array $headers): bool
    {
        $data = $this->associate($headers, $row);

        try {
            retry(3, function () use ($callback, $data, $rowNumber): void {
                DB::transaction(function () use ($callback, $data, $rowNumber): void {
                    $callback($data, $rowNumber);
                });
            }, 100);

            return true;
        } catch (Throwable $exception) {
            $this->recordFailure($batch, $rowNumber, $row, $headers, $exception);

            return false;
        }
    }

    private function recordFailure(ImportBatch $batch, int $rowNumber, array $row, array $headers, Throwable $exception): void
    {
        $data = $this->associate($headers, $row);
        ImportFailure::create([
            'import_batch_id' => $batch->id,
            'row_number' => $rowNumber,
            'source_record_id' => $this->value($data, 'service_request_no') ?: $this->value($data, 'reference_number'),
            'error_type' => 'row',
            'error_message' => Str::limit($exception->getMessage(), 1000),
            'raw_data' => $data,
        ]);
    }

    private function headers(Worksheet $sheet): array
    {
        return $this->normalizeHeaders($sheet->toArray(null, true, true, true)[1] ?? []);
    }

    private function associate(array $headers, array $row): array
    {
        // Anchor source cells by their column LETTER when the reader returns
        // letter-keyed rows (xlsx): sheets with empty leading or middle
        // columns come back sparse, and plain array_values() would silently
        // shift every later cell one slot left. List rows (CSV) map by
        // position exactly as before.
        $values = [];

        foreach ($row as $key => $value) {
            $values[is_string($key) && preg_match('/^[A-Z]{1,3}$/', $key) === 1
                ? Coordinate::columnIndexFromString($key) - 1
                : (int) $key] = $value;
        }

        $data = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $data[$header] = $values[$index] ?? null;
        }

        return $data;
    }

    private function normalizeHeaders(array $headers): array
    {
        $used = [];
        $normalized = [];

        foreach ($headers as $header) {
            $key = Str::of((string) $header)
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->toString();
            $key = $key ?: 'column';
            $base = $key;
            $suffix = 2;
            while (isset($used[$key])) {
                $key = $base.'_'.$suffix++;
            }
            $used[$key] = true;
            $normalized[] = $key;
        }

        return $normalized;
    }

    private function value(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value === null || $value === '' || in_array(strtoupper(trim((string) $value)), ['N/A', 'NA', '-', '#N/A'], true)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Like value(), but returns null instead of '' — for manual-only fields
     * (pms_frequency, tsp_in_charge) that must stay blank on auto-imported
     * records so they are never mistaken for imported data.
     */
    private function nullableValue(array $data, string $key): ?string
    {
        $value = $this->value($data, $key);

        return $value === '' ? null : $value;
    }

    private function number(string $value): ?float
    {
        if ($value === '') {
            return null;
        }

        return is_numeric(str_replace(',', '', $value)) ? (float) str_replace(',', '', $value) : null;
    }

    private function date(?string $value): ?string
    {
        return $this->dateTime($value)?->toDateString();
    }

    private function dateTime(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($value));
        if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            return $this->excelDateTime((float) $parts[0] + (float) $parts[1]);
        }

        if (is_numeric(trim($value)) && (float) $value > 20000) {
            return $this->excelDateTime((float) $value);
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function excelDateTime(float $serial): Carbon
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);

        return Carbon::create(1899, 12, 30)->addDays($days)->addSeconds($seconds);
    }

    /**
     * Stable source identity for workbook rows. The id hashes only the IDENTITY
     * columns (customer, serial, device description) — never the whole row — so
     * editing a mutable cell (status, warranty, dates…) re-imports as an UPDATE
     * of the same record instead of minting a duplicate and orphaning the old
     * row. Rows carrying a PDB "no" value include it for traceability.
     */
    private function sourceId(mixed $value, array $data, string $prefix): string
    {
        $value = trim((string) ($value ?? ''));

        return ($value !== '' ? $value.'-' : $prefix.'-').$this->identityHash($data);
    }

    private function identityHash(array $data): string
    {
        $identity = [
            $this->value($data, 'customer_name'),
            $this->value($data, 'serial_number'),
            $this->value($data, 'device_description'),
        ];

        return substr(hash('sha256', implode('|', $identity)), 0, 24);
    }

    private function rowHash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
