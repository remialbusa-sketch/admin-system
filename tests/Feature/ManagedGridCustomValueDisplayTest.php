<?php

namespace Tests\Feature;

use App\Livewire\TechnicalReportTable;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The grid payload must hand every stored custom value to the table.
 * ManagedTable::customRawValueForEditing() had no case for location,
 * person, or files and fell through to `default => null`, so the Address
 * column (typed location) showed empty cells while the values sat in the
 * database — the live "Address has no data" report. One fallback at the
 * shared boundary (the type's own toDisplayString, interface-required on
 * every type) covers the class, including future types.
 */
class ManagedGridCustomValueDisplayTest extends TestCase
{
    use RefreshDatabase;

    /** Create a one-cell custom column + row, render the grid, return the cell's payload value. */
    private function gridCell(string $name, string $type, array $value): mixed
    {
        $column = CustomTableColumn::create([
            'table_key' => 'technical-reports',
            'name' => $name,
            'type' => $type,
            'settings' => [],
            'position' => 1,
        ]);

        $report = TechnicalReport::create([
            'source_system' => 'executive',
            'source_record_id' => 'REF-'.strtolower(str_replace(' ', '-', $name)),
            'reference_number' => 'REF-'.strtolower(str_replace(' ', '-', $name)),
            'report_name' => 'Test',
        ]);

        CustomTableColumnValue::query()->create([
            'custom_column_id' => $column->id,
            'row_id' => $report->id,
            'value' => $value,
        ]);

        $html = Livewire::test(TechnicalReportTable::class)->html();
        preg_match('/data-managed-table-payload>(.*?)<\/script>/s', $html, $m);
        $payload = json_decode($m[1] ?? '{}', true);

        $key = collect($payload['columns'] ?? [])
            ->first(fn (array $c): bool => ($c['label'] ?? '') === $name)['key'] ?? null;

        $row = collect($payload['rows'] ?? [])
            ->first(fn (array $r): bool => ($r['id'] ?? null) === $report->id) ?? [];

        $this->assertNotNull($key, 'Column "'.$name.'" missing from the grid payload.');
        $this->assertNotEmpty($row, 'Row missing from the grid payload.');

        return array_key_exists($key, $row) ? $row[$key] : 'KEY_ABSENT';
    }

    public function test_location_values_reach_the_grid_payload(): void
    {
        $cell = $this->gridCell('Address', 'location', ['address' => 'Lapu-Lapu City, Cebu, Philippines']);

        $this->assertSame('Lapu-Lapu City, Cebu, Philippines', $cell);
    }

    public function test_person_values_reach_the_grid_payload(): void
    {
        $user = User::factory()->create();
        $cell = $this->gridCell('Assignee', 'person', ['user_ids' => [$user->id]]);

        $this->assertSame((string) $user->id, $cell);
    }

    public function test_files_values_reach_the_grid_payload(): void
    {
        $cell = $this->gridCell('Attachments', 'files', ['file_ids' => [11, 12]]);

        $this->assertSame('2 files', $cell);
    }
}
