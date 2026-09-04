<?php

namespace App\Enums;

/**
 * Permission levels control what an account can DO (independent of their job
 * role). Roles say who someone is; the permission says how much they may
 * touch the data:
 *
 *   viewer  — read-only access to every table, dashboard and export.
 *   editor  — viewer + inline grid edits (create/update/delete records).
 *   admin   — editor + workbook imports.
 *
 * Superadmin (a role) always has admin-level access regardless of this value.
 */
enum UserPermission: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Viewer',
            self::Editor => 'Editor',
            self::Admin => 'Admin',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Viewer => 'Read-only — dashboards, tables, exports.',
            self::Editor => 'Read + edit records in the grid.',
            self::Admin => 'Read + edit + import workbooks.',
        };
    }
}