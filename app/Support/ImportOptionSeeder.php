<?php

namespace App\Support;

use App\Models\CustomTableColumn;
use InvalidArgumentException;

/**
 * Seeds the option list of a status/dropdown column from an import cell.
 *
 * Status and dropdown values validate against column.settings.options, so a
 * file whose labels aren't configured yet used to fail every such cell (the
 * flagged import bug). Both import write paths call seed() before validate():
 * unknown labels are appended as {index, label, color} options and persisted,
 * then validate() matches them exactly.
 *
 * Candidate extraction mirrors StatusColumnType/DropdownColumnType::validate()
 * exactly — including treating integer-like numerics as index lookups, which
 * are never seeded (indexes must already exist).
 */
class ImportOptionSeeder
{
    /** Hard cap so a runaway file cannot bloat a column's option list. */
    public const MAX_OPTIONS = 200;

    /** Categorical palette for seeded options (labels keep their exact string). */
    public const COLORS = [
        '#64748B', '#2563EB', '#16A34A', '#D97706', '#7C3AED',
        '#DC2626', '#0891B2', '#DB2777', '#4F46E5', '#059669',
    ];

    /**
     * Append the cell's unknown labels to the column's options, saving only
     * when something was added. No-op for other column types and blank cells.
     *
     * @throws InvalidArgumentException when the column already holds MAX_OPTIONS
     */
    public static function seed(CustomTableColumn $column, mixed $raw): void
    {
        if (! in_array($column->type, ['status', 'dropdown'], true)) {
            return;
        }

        $settings = $column->settings ?? [];
        $options = $settings['options'] ?? [];
        $known = [];

        foreach ($options as $option) {
            // Options are {index, label, color}; tolerate legacy plain strings.
            $label = is_array($option) ? ($option['label'] ?? null) : $option;

            if (is_string($label)) {
                $known[$label] = true;
            }
        }

        $added = false;

        foreach (self::candidates($column, $raw) as $candidate) {
            if (isset($known[$candidate])) {
                continue;
            }

            if (count($options) >= self::MAX_OPTIONS) {
                throw new InvalidArgumentException(
                    'Column "'.$column->name.'" cannot accept the value "'.$candidate.'": it already has '.self::MAX_OPTIONS.' options. Import it as a text column instead.'
                );
            }

            $options[] = [
                'index' => self::nextIndex($options),
                'label' => $candidate,
                'color' => self::COLORS[count($options) % count(self::COLORS)],
            ];
            $known[$candidate] = true;
            $added = true;
        }

        if (! $added) {
            return;
        }

        $settings['options'] = $options;
        $column->settings = $settings;
        $column->save();
    }

    /**
     * The label candidates of the raw cell — exactly the values validate()
     * will look up. Index-like values are lookups, not labels, and are skipped.
     *
     * @return array<int, string>
     */
    private static function candidates(CustomTableColumn $column, mixed $raw): array
    {
        // Mirrors AbstractColumnType::valueArray(): arrays pass through,
        // JSON strings decode, everything else yields [].
        if (is_array($raw)) {
            $input = $raw;
        } elseif (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $input = is_array($decoded) ? $decoded : [];
        } else {
            $input = [];
        }

        // Mirrors StatusColumnType/DropdownColumnType::validate() exactly.
        if ($column->type === 'status') {
            $candidates = [$input['index'] ?? $input['label'] ?? $raw];
        } else {
            $selected = $input['selected'] ?? $raw;
            $candidates = is_array($selected) ? $selected : [$selected];
        }

        $labels = [];

        foreach ($candidates as $candidate) {
            if (self::isSeedableLabel($candidate)) {
                $labels[] = $candidate;
            }
        }

        return $labels;
    }

    /**
     * Would this cell value become an option label? Non-strings and blanks
     * never do; integer-like numerics are index lookups in validate() (they
     * resolve against existing indexes), never labels. The preview's
     * distinct-value list uses the same rule so the step-3 editor only
     * offers real candidates.
     */
    public static function isSeedableLabel(mixed $candidate): bool
    {
        if (! is_string($candidate) || trim($candidate) === '') {
            return false;
        }

        return ! (is_numeric($candidate) && (string) (int) $candidate === $candidate);
    }

    /**
     * @param  array<int, mixed>  $options
     */
    private static function nextIndex(array $options): int
    {
        $max = -1;

        foreach ($options as $option) {
            if (is_array($option) && isset($option['index']) && is_numeric($option['index'])) {
                $max = max($max, (int) $option['index']);
            }
        }

        return $max + 1;
    }
}
