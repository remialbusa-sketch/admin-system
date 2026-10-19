<?php

namespace App\Support;

class MondayColumnCompatibility
{
    public static function local(string $source, string $target): bool
    {
        $allowed = match ($source) {
            'number' => ['number', 'text', 'long_text'],
            'date' => ['date', 'text', 'long_text'],
            'checkbox' => ['checkbox', 'text', 'long_text'],
            'status', 'dropdown' => ['status', 'dropdown', 'text', 'long_text'],
            'email' => ['email', 'text', 'long_text'],
            'phone' => ['phone', 'text', 'long_text'],
            'person' => ['person', 'text', 'long_text'],
            'files' => ['files'],
            'link' => ['link', 'text', 'long_text'],
            'location' => ['location', 'text', 'long_text'],
            default => ['text', 'long_text', 'number', 'date', 'status', 'dropdown', 'email', 'phone', 'link', 'person', 'location', 'files', 'checkbox'],
        };

        return in_array($target, $allowed, true);
    }

    public static function core(string $field, string $source): bool
    {
        // item_id columns echo the item's own id — identity, never content.
        // A board column titled "Reference Number" of this type clobbered the
        // real reference the title write had already produced, so such a
        // source is never a core-field candidate.
        if ($source === 'item_id') {
            return false;
        }

        if (in_array($source, ['file', 'files', 'link', 'location'], true)) {
            return $field === 'report_url' && $source === 'link';
        }

        if (in_array($field, ['tsp_name', 'tsp_assignment', 'coordinator'], true)) {
            return in_array($source, ['people', 'person', 'item_assignees', 'text', 'long_text', 'mirror', 'lookup', 'board_relation'], true);
        }

        if (str_ends_with($field, '_date') || str_ends_with($field, '_at') || $field === 'date_needed') {
            return in_array($source, ['date', 'text', 'mirror', 'lookup'], true);
        }

        return true;
    }
}
