<?php

declare(strict_types=1);

namespace Bench;

/**
 * Just enough SVG charting for the results: horizontal bars, a dumbbell and a line chart.
 *
 * Charts are standalone files shown with <img>, so they carry their own light and dark
 * colors through prefers-color-scheme. Every chart has a table next to it in the post.
 */
final class Svg
{
    public const BLUE = 'series-1';
    public const ORANGE = 'series-2';
    public const AQUA = 'series-3';
    public const MUTED = 'context';

    private const STYLE = <<<'CSS'
        svg { --surface: #fcfcfb; --ink: #0b0b0b; --ink-2: #52514e; --muted: #898781; --grid: #e1e0d9; --axis: #c3c2b7;
              --series-1: #2a78d6; --series-2: #eb6834; --series-3: #1baf7a; --context: #b9b8b0; }
        @media (prefers-color-scheme: dark) {
            svg { --surface: #1a1a19; --ink: #ffffff; --ink-2: #c3c2b7; --muted: #898781; --grid: #2c2c2a; --axis: #383835;
                  --series-1: #3987e5; --series-2: #d95926; --series-3: #199e70; --context: #5c5b56; }
        }
        text { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; fill: var(--ink-2); font-size: 13px; }
        .title { fill: var(--ink); font-size: 16px; font-weight: 600; }
        .subtitle { fill: var(--ink-2); font-size: 13px; }
        .label { fill: var(--ink); }
        .value { fill: var(--ink-2); font-variant-numeric: tabular-nums; }
        .tick { fill: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums; }
        .grid { stroke: var(--grid); stroke-width: 1; }
        .axis { stroke: var(--axis); stroke-width: 1; }
        .series-1 { fill: var(--series-1); stroke: var(--series-1); }
        .series-2 { fill: var(--series-2); stroke: var(--series-2); }
        .series-3 { fill: var(--series-3); stroke: var(--series-3); }
        .context { fill: var(--context); stroke: var(--context); }
        .line { fill: none; stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
        .ring { stroke: var(--surface); stroke-width: 2; }
        .break { stroke: var(--surface); stroke-width: 4; }
        CSS;

    /**
     * @param  list<array{label: string, value: float, class: string, text: string}>  $rows
     * @param  list<array{string, string}>  $legend  [class, label] pairs
     */
    public static function bars(string $title, string $subtitle, array $rows, array $legend, ?float $cap = null, string $unit = 's'): string
    {
        $width = 720;
        $labelWidth = 190;
        $valueWidth = 90;
        $rowHeight = 30;
        $top = $legend ? 84 : 64;
        $plotWidth = $width - $labelWidth - $valueWidth - 16;
        $height = $top + count($rows) * $rowHeight + 34;

        $max = $cap ?? max(array_column($rows, 'value'));
        $ticks = self::ticks($max);
        $scaleMax = end($ticks);
        $x = fn (float $v) => $labelWidth + min($v, $scaleMax) / $scaleMax * $plotWidth;

        $svg = self::open($width, $height, $title);
        $svg .= self::heading($title, $subtitle);
        $svg .= self::legend($legend, 52);

        foreach ($ticks as $tick) {
            $svg .= sprintf('<line class="grid" x1="%.1f" x2="%.1f" y1="%d" y2="%d"/>', $x($tick), $x($tick), $top - 6, $top + count($rows) * $rowHeight);
            $svg .= sprintf('<text class="tick" x="%.1f" y="%d" text-anchor="middle">%s</text>', $x($tick), $top + count($rows) * $rowHeight + 18, self::number($tick).($tick == $scaleMax ? " $unit" : ''));
        }

        foreach ($rows as $i => $row) {
            $y = $top + $i * $rowHeight;
            $barY = $y + ($rowHeight - 18) / 2;
            $end = $x($row['value']);
            $svg .= sprintf('<text class="label" x="%d" y="%.1f" text-anchor="end" dominant-baseline="central">%s</text>', $labelWidth - 10, $y + $rowHeight / 2, self::e($row['label']));
            $svg .= sprintf('<g><title>%s: %s</title>', self::e($row['label']), self::e($row['text']));
            $svg .= self::bar($labelWidth, $barY, max($end - $labelWidth, 2), 18, $row['class']);

            if ($row['value'] > $scaleMax) {
                // Off the chart: break the bar instead of squashing every other bar to nothing.
                $svg .= sprintf('<line class="break" x1="%.1f" x2="%.1f" y1="%.1f" y2="%.1f"/>', $end - 22, $end - 14, $barY + 22, $barY - 4);
            }

            $svg .= sprintf('<text class="value" x="%.1f" y="%.1f" dominant-baseline="central">%s</text></g>', $end + 8, $y + $rowHeight / 2, self::e($row['text']));
        }

        $svg .= sprintf('<line class="axis" x1="%d" x2="%d" y1="%d" y2="%d"/>', $labelWidth, $labelWidth, $top - 6, $top + count($rows) * $rowHeight);

        return $svg.'</svg>'."\n";
    }

    /**
     * Two values per row, joined by a line. For "same thing, two conditions".
     *
     * @param  list<array{label: string, a: float, b: float, text: string}>  $rows
     * @param  array{string, string, string, string}  $series  [class a, label a, class b, label b]
     */
    public static function dumbbell(string $title, string $subtitle, array $rows, array $series, string $unit = 's'): string
    {
        $width = 720;
        $labelWidth = 190;
        $rowHeight = 30;
        $top = 84;
        $plotWidth = $width - $labelWidth - 140;
        $height = $top + count($rows) * $rowHeight + 34;

        $ticks = self::ticks(max([...array_column($rows, 'a'), ...array_column($rows, 'b')]));
        $scaleMax = end($ticks);
        $x = fn (float $v) => $labelWidth + $v / $scaleMax * $plotWidth;

        $svg = self::open($width, $height, $title);
        $svg .= self::heading($title, $subtitle);
        $svg .= self::legend([[$series[0], $series[1]], [$series[2], $series[3]]], 52, dots: true);

        foreach ($ticks as $tick) {
            $svg .= sprintf('<line class="grid" x1="%.1f" x2="%.1f" y1="%d" y2="%d"/>', $x($tick), $x($tick), $top - 6, $top + count($rows) * $rowHeight);
            $svg .= sprintf('<text class="tick" x="%.1f" y="%d" text-anchor="middle">%s</text>', $x($tick), $top + count($rows) * $rowHeight + 18, self::number($tick).($tick == $scaleMax ? " $unit" : ''));
        }

        foreach ($rows as $i => $row) {
            $cy = $top + $i * $rowHeight + $rowHeight / 2;
            $svg .= sprintf('<text class="label" x="%d" y="%.1f" text-anchor="end" dominant-baseline="central">%s</text>', $labelWidth - 10, $cy, self::e($row['label']));
            $svg .= sprintf('<line class="axis" x1="%.1f" x2="%.1f" y1="%.1f" y2="%.1f"/>', $x($row['a']), $x($row['b']), $cy, $cy);
            $svg .= sprintf('<circle class="%s ring" cx="%.1f" cy="%.1f" r="6"/>', $series[0], $x($row['a']), $cy);
            $svg .= sprintf('<circle class="%s ring" cx="%.1f" cy="%.1f" r="6"/>', $series[2], $x($row['b']), $cy);
            $svg .= sprintf('<text class="value" x="%.1f" y="%.1f" dominant-baseline="central">%s</text>', max($x($row['a']), $x($row['b'])) + 12, $cy, self::e($row['text']));
        }

        return $svg.'</svg>'."\n";
    }

    /**
     * @param  list<array{label: string, class: string, points: list<array{float, float}>}>  $series
     */
    public static function lines(string $title, string $subtitle, array $series, string $xLabel, string $yUnit, bool $logX = false): string
    {
        $series = array_values(array_filter($series, fn ($s) => $s['points'] !== []));
        $width = 720;
        $height = 400;
        $left = 64;
        $right = 150;
        $top = 84;
        $bottom = 52;
        $plotWidth = $width - $left - $right;
        $plotHeight = $height - $top - $bottom;

        $allX = array_merge(...array_map(fn ($s) => array_column($s['points'], 0), $series));
        $allY = array_merge(...array_map(fn ($s) => array_column($s['points'], 1), $series));
        $yTicks = self::ticks(max($allY));
        $yMax = end($yTicks);

        if ($logX) {
            $xTicks = array_values(array_filter([1, 10, 100, 1000, 10000, 100000], fn ($t) => $t >= min($allX) && $t <= max($allX) * 1.0001));
            $lo = log10(min($allX));
            $hi = log10(max($allX));
            $x = fn (float $v) => $left + (log10($v) - $lo) / ($hi - $lo) * $plotWidth;
        } else {
            $xTicks = self::ticks(max($allX));
            $xMax = end($xTicks);
            $x = fn (float $v) => $left + $v / $xMax * $plotWidth;
        }
        $y = fn (float $v) => $top + $plotHeight - $v / $yMax * $plotHeight;

        $svg = self::open($width, $height, $title);
        $svg .= self::heading($title, $subtitle);
        $svg .= self::legend(array_map(fn ($s) => [$s['class'], $s['label']], $series), 52, lines: true);

        foreach ($yTicks as $tick) {
            $svg .= sprintf('<line class="%s" x1="%d" x2="%d" y1="%.1f" y2="%.1f"/>', $tick == 0 ? 'axis' : 'grid', $left, $left + $plotWidth, $y($tick), $y($tick));
            $svg .= sprintf('<text class="tick" x="%d" y="%.1f" text-anchor="end" dominant-baseline="central">%s</text>', $left - 8, $y($tick), self::number($tick).($tick == $yMax ? " $yUnit" : ''));
        }
        foreach ($xTicks as $tick) {
            $svg .= sprintf('<text class="tick" x="%.1f" y="%d" text-anchor="middle">%s</text>', $x($tick), $top + $plotHeight + 18, self::number($tick));
        }
        $svg .= sprintf('<text class="tick" x="%.1f" y="%d" text-anchor="middle">%s</text>', $left + $plotWidth / 2, $height - 10, self::e($xLabel));

        // End labels, nudged apart only if two would overlap.
        $labels = [];
        foreach ($series as $s) {
            [$lx, $ly] = end($s['points']);
            $labels[] = ['y' => $y($ly), 'x' => $x($lx), 'text' => self::number($ly, 1).' '.$yUnit, 'class' => $s['class']];
        }
        usort($labels, fn ($a, $b) => $a['y'] <=> $b['y']);
        for ($i = 1; $i < count($labels); $i++) {
            $labels[$i]['y'] = max($labels[$i]['y'], $labels[$i - 1]['y'] + 16);
        }

        foreach ($series as $s) {
            $path = implode(' ', array_map(fn ($p) => sprintf('%.1f,%.1f', $x($p[0]), $y($p[1])), $s['points']));
            $svg .= sprintf('<polyline class="%s line" points="%s"/>', $s['class'], $path);
            foreach ($s['points'] as [$px, $py]) {
                $svg .= sprintf('<circle class="%s ring" cx="%.1f" cy="%.1f" r="4"><title>%s: %s posts, %s %s</title></circle>', $s['class'], $x($px), $y($py), self::e($s['label']), self::number($px), self::number($py, 2), $yUnit);
            }
        }
        foreach ($labels as $label) {
            $svg .= sprintf('<text class="value" x="%.1f" y="%.1f" dominant-baseline="central">%s</text>', $label['x'] + 10, $label['y'], self::e($label['text']));
        }

        return $svg.'</svg>'."\n";
    }

    private static function open(int $width, int $height, string $title): string
    {
        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" role="img" aria-label="%3$s"><style>%4$s</style>', $width, $height, self::e($title), self::STYLE)
            .sprintf('<rect width="%d" height="%d" rx="8" style="fill: var(--surface)"/>', $width, $height);
    }

    private static function heading(string $title, string $subtitle): string
    {
        return sprintf('<text class="title" x="20" y="28">%s</text><text class="subtitle" x="20" y="47">%s</text>', self::e($title), self::e($subtitle));
    }

    private static function legend(array $items, int $y, bool $dots = false, bool $lines = false): string
    {
        $svg = '';
        $x = 20;
        foreach ($items as [$class, $label]) {
            $svg .= match (true) {
                $lines => sprintf('<line class="%s line" x1="%d" x2="%d" y1="%d" y2="%d"/>', $class, $x, $x + 16, $y + 12, $y + 12),
                $dots => sprintf('<circle class="%s" cx="%d" cy="%d" r="5"/>', $class, $x + 5, $y + 12),
                default => sprintf('<rect class="%s" x="%d" y="%d" width="12" height="12" rx="2"/>', $class, $x, $y + 6),
            };
            $offset = $lines ? 22 : 18;
            $svg .= sprintf('<text class="label" x="%d" y="%d" dominant-baseline="central">%s</text>', $x + $offset, $y + 12, self::e($label));
            $x += $offset + (int) (mb_strlen($label) * 7.2) + 24;
        }

        return $svg;
    }

    /** A bar with a 4px rounded data end and a square baseline end. */
    private static function bar(float $x, float $y, float $width, float $height, string $class): string
    {
        $r = min(4, $width / 2);

        return sprintf(
            '<path class="%s" d="M%.1f,%.1f h%.1f a%.1f,%.1f 0 0 1 %.1f,%.1f v%.1f a%.1f,%.1f 0 0 1 -%.1f,%.1f h-%.1f z"/>',
            $class, $x, $y, $width - $r, $r, $r, $r, $r, $height - 2 * $r, $r, $r, $r, $r, $width - $r,
        );
    }

    /** @return list<float> Round tick values from 0 to at least $max. */
    private static function ticks(float $max): array
    {
        $raw = $max / 5;
        $magnitude = 10 ** floor(log10($raw));
        $step = $magnitude * (match (true) {
            $raw / $magnitude <= 1 => 1,
            $raw / $magnitude <= 2 => 2,
            $raw / $magnitude <= 2.5 => 2.5,
            $raw / $magnitude <= 5 => 5,
            default => 10,
        });

        $ticks = [];
        for ($t = 0; $t < $max + $step * 0.999; $t += $step) {
            $ticks[] = round($t, 6);
            if ($t >= $max) {
                break;
            }
        }

        return $ticks;
    }

    public static function number(float $value, int $decimals = 0): string
    {
        if ($decimals > 0 && $value < 10) {
            return number_format($value, $value < 1 ? 2 : $decimals);
        }

        return number_format($value, $value < 10 && $value != floor($value) ? 1 : 0);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1);
    }
}
