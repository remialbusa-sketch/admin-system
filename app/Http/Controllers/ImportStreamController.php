<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Chunked upload receiver for the import wizard.
 *
 * Browsers upload the workbook in small PUT bodies instead of one multipart
 * POST, so PHP's upload_max_filesize / post_max_size never apply and the
 * wizard works on any machine with stock php.ini. Chunks are appended to a
 * single file keyed by a client-generated upload id; the Livewire component
 * analyzes the assembled file once the last chunk lands.
 */
class ImportStreamController extends Controller
{
    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'csv', 'txt'];

    private const MAX_TOTAL_BYTES = 536870912; // 512 MB

    public function store(Request $request)
    {
        $originalName = (string) $request->header('X-File-Name', '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $uploadId = (string) $request->header('X-Upload-Id', '');
        $offset = (int) $request->header('X-File-Offset', '-1');

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return response()->json(['message' => 'Unsupported file type.'], 422);
        }

        if (preg_match('/^[a-f0-9]{32}$/', $uploadId) !== 1 || $offset < 0) {
            return response()->json(['message' => 'Malformed upload request.'], 422);
        }

        $relative = self::storedPathFor($uploadId, $extension);
        $absolute = Storage::disk(config('filesystems.default'))->path($relative);
        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $currentSize = is_file($absolute) ? filesize($absolute) : 0;

        if ($offset > 0 && $currentSize !== $offset) {
            return response()->json(['message' => 'Chunk out of order - restart the upload.'], 409);
        }

        if ($currentSize + (int) $request->header('Content-Length', '0') > self::MAX_TOTAL_BYTES) {
            return response()->json(['message' => 'File exceeds the 512 MB import cap.'], 413);
        }

        // getContent() over the streamed PUT body: works identically to
        // php://input in production (PUT bodies are exempt from
        // post_max_size) but is also readable from the test client.
        $body = $request->getContent();

        if ($body === '') {
            return response()->json(['message' => 'Empty upload chunk.'], 422);
        }

        $target = fopen($absolute, $offset === 0 ? 'wb' : 'ab');

        if ($target === false) {
            return response()->json(['message' => 'Could not write the upload.'], 500);
        }

        fwrite($target, $body);
        fclose($target);

        return response()->json([
            'stored' => $relative,
            'size' => filesize($absolute),
        ]);
    }

    /**
     * Where a streamed upload lands for a given upload id + extension. The
     * Livewire component rebuilds the same path to analyze the file, so this
     * is the single source of truth for the layout.
     */
    public static function storedPathFor(string $uploadId, string $extension): string
    {
        return 'imports/stream-'.$uploadId.'.'.strtolower($extension);
    }
}
