<?php

namespace Tests\Unit;

use App\Services\ImportMappingService;
use Tests\TestCase;

class ImportHeaderAliasTest extends TestCase
{
    /**
     * Real files abbreviate headers ("SR No", "TSP ASSIGNED") that never
     * resemble the fixed field names, so auto-mapping left the fixed fields
     * empty and the data landed in lookalike custom columns. Declared
     * aliases match exactly and win the suggestion.
     */
    public function test_suggest_mapping_honors_declared_header_aliases(): void
    {
        $fields = [
            ['key' => 'service_request_number', 'label' => 'Service Request Number', 'aliases' => ['sr no', 'sr']],
            ['key' => 'tsp_name', 'label' => 'TSP Name', 'aliases' => ['tsp assigned']],
            ['key' => 'brand', 'label' => 'Brand'],
        ];
        $columns = [
            ['letter' => 'A', 'label' => 'SR No'],
            ['letter' => 'B', 'label' => 'TSP ASSIGNED'],
            ['letter' => 'C', 'label' => 'Brand'],
            ['letter' => 'D', 'label' => 'Notes'],
        ];

        $mapping = (new ImportMappingService)->suggestMapping($fields, $columns);

        $this->assertSame('A', $mapping['service_request_number'] ?? null);
        $this->assertSame('B', $mapping['tsp_name'] ?? null);
        $this->assertSame('C', $mapping['brand'] ?? null);
        $this->assertNotContains('D', array_values($mapping));
    }

    /** Fields without aliases behave exactly as before. */
    public function test_suggest_mapping_ignores_missing_aliases(): void
    {
        $fields = [
            ['key' => 'brand', 'label' => 'Brand'],
        ];
        $columns = [
            ['letter' => 'A', 'label' => 'Brand'],
        ];

        $this->assertSame(['brand' => 'A'], (new ImportMappingService)->suggestMapping($fields, $columns));
    }
}
