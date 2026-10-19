<?php

namespace Tests\Feature;

use App\Jobs\MondaySyncItemJob;
use App\Models\CustomTableColumn;
use App\Models\MondaySyncSetting;
use App\Services\MondaySyncService;
use App\Services\TableAggregationService;
use App\Support\DashboardCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_cache_invalidates_product_and_tsa_summaries(): void
    {
        // Prime caches
        Cache::put('product:summary:all:12', ['primed' => true]);
        Cache::put('product:summary:all:12:'.md5(json_encode([])), ['primed' => true]);
        Cache::put('tsa:summary:30', ['primed' => true]);
        Cache::put(TableAggregationService::CACHE_VERSION_KEY, 1);

        DashboardCache::invalidateDomain('installed-products');

        $this->assertFalse(Cache::has('product:summary:all:12'));
        $this->assertFalse(Cache::has('product:summary:all:12:'.md5(json_encode([]))));
        $this->assertTrue(Cache::has('tsa:summary:30'), 'TSA summary should not be cleared when product domain syncs.');
        $this->assertSame(2, (int) Cache::get(TableAggregationService::CACHE_VERSION_KEY));

        DashboardCache::invalidateDomain('technical-reports');

        $this->assertFalse(Cache::has('tsa:summary:30'), 'TSA summary should be cleared when technical-reports domain syncs.');
    }

    public function test_monday_sync_service_invalidates_cache_when_items_imported(): void
    {
        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);

        MondaySyncSetting::create([
            'domain' => 'technical-reports',
            'board_id' => '10',
            'enabled' => true,
            'title_field' => 'reference_number',
        ]);

        CustomTableColumn::create([
            'table_key' => 'technical-reports',
            'name' => 'Brand',
            'type' => 'text',
            'position' => 0,
        ]);
        $brandId = CustomTableColumn::query()->where('table_key', 'technical-reports')->where('name', 'Brand')->value('id');
        MondaySyncSetting::forDomain('technical-reports')->update(['field_map' => ['br1' => $brandId]]);

        Cache::put('tsa:summary:30', ['primed' => true]);

        Http::fake([
            'https://api.monday.com/v2' => function ($request) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'items_page')) {
                    return Http::response([
                        'data' => ['boards' => [[
                            'id' => '10',
                            'items_page' => ['cursor' => null, 'items' => [
                                ['id' => '999', 'name' => 'REF-999', 'created_at' => null, 'updated_at' => null],
                            ]],
                        ]]],
                    ]);
                }

                if (str_contains($query, 'query Items')) {
                    return Http::response([
                        'data' => ['items' => [[
                            'id' => '999',
                            'name' => 'REF-999',
                            'created_at' => null,
                            'updated_at' => null,
                            'column_values' => [['id' => 'br1', 'text' => 'Sysmex', 'type' => 'text']],
                        ]]],
                    ]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        $service = app(MondaySyncService::class);
        $result = $service->syncDomain('technical-reports');

        $this->assertSame(1, $result['imported']);
        $this->assertFalse(Cache::has('tsa:summary:30'), 'tsa:summary cache should have been invalidated after sync.');
    }

    public function test_monday_sync_job_invalidates_cache_on_success(): void
    {
        config(['monday.token' => 't']);
        config(['monday.enabled' => true]);

        MondaySyncSetting::create([
            'domain' => 'technical-reports',
            'board_id' => '10',
            'enabled' => true,
            'title_field' => 'reference_number',
        ]);

        CustomTableColumn::create([
            'table_key' => 'technical-reports',
            'name' => 'Brand',
            'type' => 'text',
            'position' => 0,
        ]);
        $brandId = CustomTableColumn::query()->where('table_key', 'technical-reports')->where('name', 'Brand')->value('id');
        MondaySyncSetting::forDomain('technical-reports')->update(['field_map' => ['br1' => $brandId]]);

        Cache::put('tsa:summary:30', ['primed' => true]);

        Http::fake([
            'https://api.monday.com/v2' => Http::response([
                'data' => ['items' => [[
                    'id' => '555',
                    'name' => 'REF-555',
                    'created_at' => null,
                    'updated_at' => null,
                    'column_values' => [['id' => 'br1', 'text' => 'Sysmex', 'type' => 'text']],
                ]]],
            ]),
        ]);

        $job = new MondaySyncItemJob('555', '10');
        app()->call([$job, 'handle']);

        $this->assertFalse(Cache::has('tsa:summary:30'), 'tsa:summary cache should have been invalidated after webhook job.');
    }
}
