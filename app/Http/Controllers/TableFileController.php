<?php

namespace App\Http\Controllers;

use App\Models\CustomTableColumn;
use App\Models\CustomTableColumnValue;
use App\Models\DynamicRow;
use App\Models\DynamicTable;
use App\Services\MondayApiClient;
use App\Support\AttachmentValue;
use App\Support\MondayCoreTargets;
use Illuminate\Support\Facades\Cache;

class TableFileController extends Controller
{
    public function show(CustomTableColumn $column, int $row, int $file, MondayApiClient $client)
    {
        $dynamic = DynamicTable::where('key', $column->table_key)->first();
        if ($dynamic) {
            abort_unless($dynamic->canBeViewedBy(auth()->user()), 403);
            abort_unless(DynamicRow::where('table_key', $column->table_key)->whereKey($row)->exists(), 404);
        } else {
            $target = MondayCoreTargets::resolve($column->table_key);
            abort_unless($target && $target['model']::whereKey($row)->exists(), 404);
        }
        $value = CustomTableColumnValue::where('custom_column_id', $column->id)->where('row_id', $row)->firstOrFail();
        $attachment = AttachmentValue::files($value->value)[$file] ?? null;
        abort_unless($attachment, 404);
        $url = $attachment['url'];
        if ($attachment['asset_id'] !== null) {
            $assetId = $attachment['asset_id'];
            $url = Cache::remember('monday.asset-url.'.$assetId, now()->addMinutes(45), fn () => $client->assetUrl($assetId));
        }
        abort_unless(is_string($url) && AttachmentValue::safeUrl($url), 404);

        // Privately cacheable so repeat <img> hits (page flips, reloads, tab
        // switches) come from the browser cache instead of burning the
        // route's request budget — a grid page costs one request per image
        // cell per TTL, not per view. The TTL stays under the 45-minute
        // monday asset-URL cache above, so a cached redirect never outlives
        // the URL it points at.
        return redirect()->away($url)->header('Cache-Control', 'private, max-age=900');
    }
}
