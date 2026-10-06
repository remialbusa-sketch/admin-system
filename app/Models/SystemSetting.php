<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Single key/value store for workspace-wide settings that used to live in
 * .env — currently the monday.com api token + global enable flag, edited on
 * the superadmin Settings page. Reads go through App\Support\MondaySettings
 * (the one boundary that applies the DB-over-env fallback), never raw.
 */
class SystemSetting extends Model
{
    use HasFactory;

    protected $table = 'system_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = static::query()->where('key', $key)->value('value');

        return $value === null ? $default : (string) $value;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
