<?php

namespace App\Support;

/** Shared decoder for monday assets, imported image URLs, and file cells. */
class AttachmentValue
{
    public static function files(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : ['text' => $value];
        }
        if (! is_array($value)) {
            return [];
        }
        $input = $value['files'] ?? null;
        if ($input === null) {
            $url = $value['url'] ?? $value['text'] ?? null;
            if (! is_string($url) || ! self::safeUrl(trim($url))) {
                return [];
            }
            $input = [['url' => trim($url)]];
        }
        $files = [];
        foreach ($input as $file) {
            if (! is_array($file)) {
                continue;
            }
            $assetId = $file['asset_id'] ?? $file['assetId'] ?? $file['asset']['id'] ?? null;
            $url = $file['url'] ?? $file['asset']['url'] ?? null;
            $url = is_string($url) && self::safeUrl($url) ? $url : null;
            if ($assetId === null && $url !== null && preg_match('~^https://[^/]+\.monday\.com/protected_static/\d+/resources/(\d+)/~i', $url, $m)) {
                $assetId = $m[1];
            }
            $assetId = is_numeric($assetId) && (int) $assetId > 0 ? (int) $assetId : null;
            if ($assetId === null && $url === null) {
                continue;
            }
            $name = (string) ($file['name'] ?? ($url ? rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?: '')) : 'Attachment'));
            $files[] = [
                'asset_id' => $assetId,
                'name' => mb_substr($name, 0, 255),
                'url' => $url,
                'is_image' => (bool) ($file['is_image'] ?? preg_match('/\.(png|jpe?g|gif|webp|avif|bmp)$/i', $name)),
            ];
        }

        return $files;
    }

    public static function safeUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && parse_url($url, PHP_URL_USER) === null;
    }

    public static function grid(mixed $value, int $columnId, int $rowId, bool $fileColumn): ?array
    {
        $files = self::files($value);
        if ($files === [] || (! $fileColumn && collect($files)->contains(fn ($file) => ! $file['is_image']))) {
            return null;
        }
        foreach ($files as $index => &$file) {
            if ($file['asset_id'] !== null) {
                // Asset public URLs expire. Resolve on image request, never in
                // a Livewire render or by saving an expiring URL in the DB.
                $file['url'] = route('table-files.show', ['column' => $columnId, 'row' => $rowId, 'file' => $index]);
            }
        }

        return ['files' => $files];
    }
}
