<?php

namespace Tests\Feature;

use App\Livewire\TechnicalReportTable;
use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Services\MondayApiClient;
use App\Support\AttachmentValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class TableFileRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_signature_url_reaches_grid_as_an_image_with_an_authenticated_url(): void
    {
        $column = CustomTableColumn::create(['table_key' => 'technical-reports', 'name' => 'TSP Signature', 'type' => 'text', 'position' => 1]);
        $report = TechnicalReport::create(['source_system' => 'executive_dashboard', 'source_record_id' => 'row-1', 'reference_number' => 'REF1']);
        CustomTableColumnValue::create(['custom_column_id' => $column->id, 'row_id' => $report->id, 'value' => ['text' => 'https://test.monday.com/protected_static/123/resources/456/signature.png']]);
        $html = Livewire::test(TechnicalReportTable::class)->html();
        preg_match('/data-managed-table-payload>(.*?)<\/script>/s', $html, $m);
        $payload = json_decode($m[1], true);
        $file = $payload['rows'][0]['custom_'.$column->id]['files'][0];
        $this->assertTrue($file['is_image']);
        $this->assertSame('signature.png', $file['name']);
        $this->assertSame(route('table-files.show', [$column->id, $report->id, 0]), $file['url']);
    }

    public function test_private_assets_require_table_access_and_refresh_expiring_urls(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        DynamicTable::create(['key' => 'private-files', 'name' => 'Files', 'created_by' => $owner->id]);
        $row = DynamicRow::create(['table_key' => 'private-files', 'name' => 'File row']);
        $column = CustomTableColumn::create(['table_key' => 'private-files', 'name' => 'Image', 'type' => 'files', 'position' => 1]);
        CustomTableColumnValue::create(['custom_column_id' => $column->id, 'row_id' => $row->id, 'value' => ['files' => [['asset_id' => 456, 'name' => 'photo.png', 'is_image' => true]]]]);
        $url = route('table-files.show', [$column->id, $row->id, 0]);
        $this->get($url)->assertRedirect(route('login'));
        $client = $this->mock(MondayApiClient::class);
        $client->shouldReceive('assetUrl')->once()->with(456)->andReturn('https://example.com/fresh-photo.png');
        $this->actingAs($stranger)->get($url)->assertForbidden();
        $this->actingAs($owner)->get($url)->assertRedirect('https://example.com/fresh-photo.png');
        $this->get($url)->assertRedirect('https://example.com/fresh-photo.png');
        $this->get(route('table-files.show', [$column->id, $row->id + 100, 0]))->assertNotFound();
        $this->get(route('table-files.show', [$column->id, $row->id, 1]))->assertNotFound();
    }

    public function test_image_redirects_cache_privately_and_the_budget_covers_grid_browsing(): void
    {
        $column = CustomTableColumn::create(['table_key' => 'technical-reports', 'name' => 'TSP Signature', 'type' => 'text', 'position' => 1]);
        $report = TechnicalReport::create(['source_system' => 'executive_dashboard', 'source_record_id' => 'row-cache', 'reference_number' => 'REF2']);
        CustomTableColumnValue::create(['custom_column_id' => $column->id, 'row_id' => $report->id, 'value' => ['text' => 'https://test.monday.com/protected_static/123/resources/456/photo.png']]);

        $client = $this->mock(MondayApiClient::class);
        $client->shouldReceive('assetUrl')->once()->with(456)->andReturn('https://example.com/fresh-photo.png');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('table-files.show', [$column->id, $report->id, 0]));

        $response->assertRedirect('https://example.com/fresh-photo.png');

        // A grid page spends one request per image cell; repeats (page flips,
        // reloads, tab switches) must come from the browser cache or the
        // route's throttle empties mid-browse and every image 429s.
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age=900', $cache);
        $this->assertStringNotContainsString('no-store', $cache);

        $this->assertContains(
            'throttle:1200,1',
            Route::getRoutes()->getByName('table-files.show')->gatherMiddleware(),
        );
    }

    public function test_attachment_decoder_rejects_executable_urls_and_keeps_nonimages_as_files(): void
    {
        $this->assertSame([], AttachmentValue::files(['text' => 'javascript:alert(1)']));
        $this->assertSame([], AttachmentValue::files(['text' => 'data:image/svg+xml,test']));
        $pdf = AttachmentValue::files(['files' => [['url' => 'https://example.com/report.pdf', 'name' => 'Report.pdf']]]);
        $this->assertFalse($pdf[0]['is_image']);
    }
}
