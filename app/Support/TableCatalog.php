<?php

namespace App\Support;

use App\Models\DynamicTable;

/**
 * Central catalog of tables — both the built-in (core) tables and user-created
 * (dynamic) tables — keyed by a stable table_key. Single source of truth for
 * resolving a table key to its route / label / icon, used by the sidebar
 * (pinned tables) and the Tables page.
 */
class TableCatalog
{
    /**
     * The built-in tables. Route is the route-name used by the sidebar's
     * active-link check; params is the optional route parameter array.
     *
     * @var array<string, array{route: string, params?: array, label: string, description: string, icon: string}>
     */
    public const CORE = [
        'installed-products' => ['route' => 'installed-products', 'label' => 'Product Database', 'description' => 'Product database (PDB)', 'icon' => 'o-cube'],
        'service-requests' => ['route' => 'service-requests', 'label' => 'Service Requests', 'description' => 'From Executive Dashboard', 'icon' => 'o-inbox-stack'],
        'technical-reports' => ['route' => 'technical-reports', 'label' => 'Technical Reports', 'description' => 'From Executive Dashboard', 'icon' => 'o-document-text'],
        'history-reports' => ['route' => 'history-reports', 'label' => 'History Reports', 'description' => 'MCBTSi TSMS (Responses)', 'icon' => 'o-archive-box'],
        'personnel' => ['route' => 'personnel', 'label' => 'Technical Personnel', 'description' => 'Personnel list', 'icon' => 'o-user-group'],
    ];

    /**
     * Resolve a table_key to a nav item (core or dynamic). Returns null when
     * the key is unknown (a pin left behind after a dynamic table was deleted).
     *
     * @return array{key: string, route: string, params?: array, label: string, description: string, icon: string}|null
     */
    public function resolve(string $key): ?array
    {
        if (isset(self::CORE[$key])) {
            return ['key' => $key] + self::CORE[$key];
        }

        $table = DynamicTable::query()->where('key', $key)->first();

        if ($table) {
            return [
                'key' => $key,
                'route' => 'tables.show',
                'params' => ['table' => $key],
                'label' => $table->name,
                'description' => (string) ($table->description ?: 'User-created table'),
                'icon' => $table->icon ?? 'o-table-cells',
            ];
        }

        return null;
    }

    /**
     * Resolve an ordered list of table keys to nav items, skipping unknown
     * keys (e.g. deleted dynamic tables).
     *
     * @param  iterable<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    public function navForKeys(iterable $keys): array
    {
        $items = [];

        foreach ($keys as $key) {
            $item = $this->resolve((string) $key);

            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Whether a table_key exists (core or dynamic) — used before pinning.
     */
    public function exists(string $key): bool
    {
        if (isset(self::CORE[$key])) {
            return true;
        }

        return DynamicTable::query()->where('key', $key)->exists();
    }
}
