<?php

namespace App\Support;

use App\Models\HistoricalTsmsReport;
use App\Models\Installation;
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;
use App\Models\TechnicalReport;

/**
 * Registry mapping the five core (built-in) table keys to the domain model
 * that receives monday.com items, plus where the item name lands and which
 * fields the connect modal offers as title remaps.
 *
 * Core tables have fixed domain schemas, so a board item becomes a domain row
 * (source_system = monday:<key>, source_record_id = monday item id) with the
 * board's columns auto-created as table columns — the same custom-column
 * storage their grids already render. Dynamic tables keep the DynamicRow
 * path (see MondayItemMapper::mapItem).
 *
 * `columns` maps a squished-lowercase board column label to the DOMAIN
 * column it feeds alongside its custom twin: the grids, filters and
 * dashboards read domain columns, so a sync that only writes customs leaves
 * the core columns empty (the 2026-10-09 "empty Brand / Serial Number"
 * report). Only labels verified against the connected boards belong here;
 * manual-only domain fields (import rule: pms_frequency, tsp_in_charge) are
 * deliberately absent. Domains get their map when their board connects.
 */
class MondayCoreTargets
{
    /**
     * @var array<string, array{model: class-string, title: string, title_options: array<string, string>, columns?: array<string, string>}>
     */
    public const TARGETS = [
        'installed-products' => [
            'model' => Installation::class,
            'title' => 'device_description',
            'title_options' => [
                'device_description' => 'Device description',
                'serial_number' => 'Serial number',
                'brand' => 'Brand',
                'machine_type' => 'Machine type',
                'bu_no' => 'BU no.',
            ],
            // The workbook's device_description holds model names
            // ("XN-550", "UF-4000i") — the board's Model column is its twin.
            'columns' => [
                'brand' => 'brand',
                'serial number' => 'serial_number',
                'bu no.' => 'bu_no',
                'model' => 'device_description',
                'system type' => 'equipment_type',
                'installation date' => 'installation_date',
                'uninstallation date' => 'uninstallation_date',
                'device status' => 'device_status',
                'device ownership' => 'device_ownership',
                'deal type' => 'deal_type',
                'warranty status' => 'warranty_status',
                'service contract status' => 'service_contract_status',
            ],
        ],
        'service-requests' => [
            'model' => ServiceRequest::class,
            'title' => 'customer_name',
            'title_options' => [
                'customer_name' => 'Customer name',
                'service_request_number' => 'Service request #',
                'concerns' => 'Concerns',
                'requesting_entity' => 'Requesting entity',
            ],
            'columns' => [
                'customer name' => 'customer_name',
                'ticket status' => 'ticket_status',
                'branch' => 'branch',
                'brand' => 'brand',
                'model' => 'machine_type',
                'requestor name' => 'requestor_name',
                'requestor email' => 'requestor_email',
                'coordinator' => 'coordinator',
                'contract type' => 'contract_type',
                'type of request' => 'request_type',
                'department' => 'department',
                'date needed' => 'date_needed',
                'device ownership' => 'device_ownership',
                'tsp' => 'tsp_assignment',
            ],
        ],
        'technical-reports' => [
            'model' => TechnicalReport::class,
            'title' => 'reference_number',
            'title_options' => [
                'reference_number' => 'Reference #',
                'customer_name' => 'Customer name',
                'report_name' => 'Report name',
                'brand' => 'Brand',
            ],
        ],
        'history-reports' => [
            'model' => HistoricalTsmsReport::class,
            'title' => 'account_name',
            'title_options' => [
                'account_name' => 'Account name',
                'csr_number' => 'CSR #',
                'problem_or_complaint' => 'Problem / complaint',
                'brand' => 'Brand',
            ],
        ],
        'personnel' => [
            'model' => TechnicalPersonnel::class,
            'title' => 'name',
            'title_options' => [
                'name' => 'Name',
                'position' => 'Position',
                'branch' => 'Branch',
                'region' => 'Region',
            ],
        ],
    ];

    public static function has(string $tableKey): bool
    {
        return isset(self::TARGETS[$tableKey]);
    }

    /**
     * @return array{model: class-string, title: string, title_options: array<string, string>, columns?: array<string, string>}|null
     */
    public static function resolve(string $tableKey): ?array
    {
        return self::TARGETS[$tableKey] ?? null;
    }

    /**
     * The title-remap choices for a table's connect modal (empty for dynamic
     * tables — their name column is fixed).
     *
     * @return array<string, string> field => label
     */
    public static function titleFieldOptions(string $tableKey): array
    {
        return self::TARGETS[$tableKey]['title_options'] ?? [];
    }

    /**
     * The effective title field for a domain: the setting's override when it
     * is one of the allowed options, otherwise the target's default.
     */
    public static function titleField(string $tableKey, ?string $override): string
    {
        $target = self::resolve($tableKey);

        if ($target === null) {
            return '';
        }

        if ($override !== null && $override !== '' && array_key_exists($override, $target['title_options'])) {
            return $override;
        }

        return $target['title'];
    }
}
