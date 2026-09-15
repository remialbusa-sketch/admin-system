<?php

namespace App\Support\Dashboard;

/**
 * Pure SVG geometry for trend widgets: values in, path strings out.
 * No data is invented — fewer than two points yields empty paths that the
 * view renders as a teaching empty state.
 */
final class SvgTrend
{
    /**
     * @param  array<int, float|int>  $values
     * @return array{area: string, line: string, dots: array<int, array{0: float, 1: float}>, max: float, last: float}
     */
    public static function build(array $values, int $width = 640, int $height = 120, int $pad = 8): array
    {
        $values = array_values(array_map('floatval', $values));
        $count = count($values);

        if ($count < 2) {
            return ['area' => '', 'line' => '', 'dots' => [], 'max' => 0.0, 'last' => $values[0] ?? 0.0];
        }

        $max = max(1.0, max($values));
        $stepX = ($width - $pad * 2) / ($count - 1);

        $points = [];

        foreach ($values as $i => $value) {
            $points[] = [
                round($pad + $i * $stepX, 1),
                round($height - $pad - (($value / $max) * ($height - $pad * 2)), 1),
            ];
        }

        $join = fn (array $points) => implode(' L ', array_map(fn (array $p) => "{$p[0]} {$p[1]}", $points));

        $line = 'M '.$join($points);
        $area = 'M '.$pad.','.($height - $pad)
            .' L '.$join($points)
            .' L '.($width - $pad).','.($height - $pad).' Z';

        return ['area' => $area, 'line' => $line, 'dots' => $points, 'max' => $max, 'last' => $values[$count - 1]];
    }
}
