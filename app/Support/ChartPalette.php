<?php

namespace App\Support;

/**
 * Categorical color palette for chart segments. Eight visually distinct hues
 * (defined as CSS variables in app.css so both light and dark themes render
 * them well) — every slice of a categorical donut gets its own color.
 * Semantic state colors (success/warning/error) stay on status-type donuts;
 * this palette is for identity/categorical series like brands.
 */
class ChartPalette
{
    private const PALETTE = [
        'var(--chart-1)',
        'var(--chart-2)',
        'var(--chart-3)',
        'var(--chart-4)',
        'var(--chart-5)',
        'var(--chart-6)',
        'var(--chart-7)',
        'var(--chart-8)',
    ];

    public static function color(int $index): string
    {
        return self::PALETTE[abs($index) % count(self::PALETTE)];
    }

    public static function neutral(): string
    {
        return 'var(--color-base-300)';
    }
}
