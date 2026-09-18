<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Captures PHP FATAL errors (memory exhaustion, timeouts) during workbook
 * analysis — the errors that try/catch can never see.
 *
 * The persistent live 500 on srf.mcbtsi.com was an uncatchable OOM fatal
 * (PhpSpreadsheet formula enumeration), so no application-level handler ever
 * ran. A shutdown function still runs after a fatal: this writes a small
 * marker to storage/app/private/imports/last-fatal.json (that directory is
 * already writable — streamed uploads land there) and, for plain JSON
 * endpoints, emits an actionable JSON error instead of an empty 500.
 */
class ImportFatalCapture
{
    private const FATAL_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
        E_RECOVERABLE_ERROR,
    ];

    public static function register(string $context, bool $emitJson = false): void
    {
        register_shutdown_function(function () use ($context, $emitJson): void {
            $error = error_get_last();

            if ($error === null || ! in_array($error['type'], self::FATAL_TYPES, true)) {
                return;
            }

            $marker = [
                'context' => $context,
                'time' => date('c'),
                'error' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'memory_limit' => ini_get('memory_limit'),
                'peak_bytes' => memory_get_peak_usage(true),
            ];

            $directory = storage_path('app/private/imports');

            if (is_dir($directory) || @mkdir($directory, 0775, true)) {
                @file_put_contents(
                    $directory.'/last-fatal.json',
                    json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                );
            }

            // Best-effort log too (may itself fail if storage/logs is the problem).
            try {
                Log::error('import.fatal', $marker);
            } catch (\Throwable) {
                // never mask the original fatal
            }

            if (! $emitJson) {
                return;
            }

            if (! headers_sent()) {
                // A fatal can leave partial output in the buffers; drop it so
                // the JSON body is parseable by the fetch() caller.
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }

                http_response_code(500);
                header('Content-Type: application/json');

                echo json_encode([
                    'message' => 'The server hit a fatal error while processing the workbook: '
                        .$error['message']
                        .' (memory_limit='.ini_get('memory_limit').'). '
                        .'Ask the host to raise memory_limit to 512M, or use a smaller file. '
                        .'Details were written to storage/app/private/imports/last-fatal.json.',
                ]);
            }
        });
    }
}
