<?php

declare(strict_types=1);

namespace Preflow\Folio\View;

/**
 * The ink layer's marks (INK.md): editing marks scattered on an ink surface, placed from a fixed
 * seed so a screen looks the same on every request. Templates call folio_ink_marks(seed, count,
 * area); the sign-in keeps its own hand-placed marks (_auth_pattern.twig).
 *
 * The area is a box inside the ink surface, as fractions of its width and height, e.g.
 * ['x' => [0.5, 1.0], 'y' => [0, 1]] for the right half, so the marks stay away from the text.
 * Each mark is drawn inside the area with its whole size whenever the area is at least that wide
 * (box() is the geometry svg() draws with). Every glyph fits a circle of radius 12 in its 24×24
 * symbol, so a rotated mark stays inside its square too.
 */
final class InkMarks
{
    /** The symbols in assets/icons.svg, as "mark-{name}". */
    public const array MARKS = ['caret', 'pilcrow', 'brace', 'block', 'cursor', 'squiggle', 'plus', 'ring', 'dot'];

    public const int MAX = 60;

    /** The drawn side of a mark in CSS pixels. */
    private const float SIZE_MIN = 24.0;
    private const float SIZE_MAX = 52.0;

    /** Candidates per mark: the one farthest from the marks already placed wins, so they spread out. */
    private const int CANDIDATES = 6;

    /**
     * @param array{x?: array{0?: float|int, 1?: float|int}, y?: array{0?: float|int, 1?: float|int}} $area fractions of the box
     * @return list<array{mark: string, x: float, y: float, size: float, rotate: int, hue: int}>
     *         x/y: the mark's anchor as fractions of the box (inside the area); size in px; rotate in degrees; hue 1..6
     */
    public static function place(int $seed, int $count, array $area): array
    {
        $count = max(0, min(self::MAX, $count));
        [$x0, $x1] = self::span($area['x'] ?? null);
        [$y0, $y1] = self::span($area['y'] ?? null);
        $next = self::generator($seed);

        $marks = [];
        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $best = null;
            $bestDistance = -1.0;
            for ($c = 0; $c < self::CANDIDATES; $c++) {
                $candidate = [$next(), $next()];
                $distance = INF;
                foreach ($points as $point) {
                    $distance = min($distance, ($candidate[0] - $point[0]) ** 2 + ($candidate[1] - $point[1]) ** 2);
                }
                if ($distance > $bestDistance) {
                    $best = $candidate;
                    $bestDistance = $distance;
                }
            }
            \assert($best !== null);
            $points[] = $best;

            $marks[] = [
                'mark' => self::MARKS[(int) floor($next() * count(self::MARKS))],
                'x' => min($x1, max($x0, round($x0 + $best[0] * ($x1 - $x0), 4))),
                'y' => min($y1, max($y0, round($y0 + $best[1] * ($y1 - $y0), 4))),
                'size' => round(self::SIZE_MIN + $next() * (self::SIZE_MAX - self::SIZE_MIN), 1),
                'rotate' => -40 + 5 * (int) floor($next() * 18),
                'hue' => 1 + (int) floor($next() * 6),
            ];
        }

        return $marks;
    }

    /**
     * Where a mark is drawn in a box of $width × $height px: [left, top, right, bottom]. The anchor
     * moves the mark's square from the area's left edge (anchor at the start) to its right edge
     * (anchor at the end), so the square stays inside the area.
     *
     * @param array{x: float, y: float, size: float} $mark
     * @param array{x?: array{0?: float|int, 1?: float|int}, y?: array{0?: float|int, 1?: float|int}} $area
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function box(array $mark, array $area, float $width, float $height): array
    {
        [$u, $v] = self::offsets($mark, $area);
        $left = $mark['x'] * $width - $u * $mark['size'];
        $top = $mark['y'] * $height - $v * $mark['size'];

        return [$left, $top, $left + $mark['size'], $top + $mark['size']];
    }

    /**
     * The layer: <svg class="folio-ink-marks" aria-hidden="true"> with one <use> of a mark-* symbol
     * of $icons (the icons.svg URL) per mark, in its own viewport at the anchor (percentages of the box).
     *
     * @param array{x?: array{0?: float|int, 1?: float|int}, y?: array{0?: float|int, 1?: float|int}} $area
     */
    public static function svg(string $icons, int $seed, int $count, array $area): string
    {
        $href = htmlspecialchars($icons, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '';
        foreach (self::place($seed, $count, $area) as $mark) {
            [$u, $v] = self::offsets($mark, $area);
            $size = $mark['size'];
            $out .= '<svg x="' . self::num($mark['x'] * 100) . '%" y="' . self::num($mark['y'] * 100) . '%" overflow="visible">'
                . '<use href="' . $href . '#mark-' . $mark['mark'] . '" class="mc-' . $mark['hue'] . '" width="24" height="24"'
                . ' transform="translate(' . self::num((0.5 - $u) * $size) . ' ' . self::num((0.5 - $v) * $size) . ') rotate(' . $mark['rotate'] . ') scale(' . self::num($size / 24) . ') translate(-12 -12)"/>'
                . '</svg>';
        }

        return '<svg class="folio-ink-marks" aria-hidden="true" focusable="false">' . $out . '</svg>';
    }

    /**
     * The first $n numbers of the generator for $seed: mulberry32, as in JavaScript, so a browser
     * port gives the same marks.
     *
     * @return list<float>
     */
    public static function sequence(int $seed, int $n): array
    {
        $next = self::generator($seed);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $next();
        }

        return $out;
    }

    /** mulberry32 on 32-bit unsigned state; each call returns a float in [0, 1). @return \Closure(): float */
    private static function generator(int $seed): \Closure
    {
        $state = $seed & 0xFFFFFFFF;

        return static function () use (&$state): float {
            $state = ($state + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = self::imul($state ^ ($state >> 15), $state | 1);
            $t = (($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF) ^ $t;

            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
    }

    /** Math.imul for unsigned 32-bit operands, without overflowing PHP's 64-bit integers. */
    private static function imul(int $a, int $b): int
    {
        $low = ($a & 0xFFFF) * $b;
        $high = (($a >> 16) * $b) & 0xFFFF;

        return ($low + ($high << 16)) & 0xFFFFFFFF;
    }

    /** An area axis as [start, end] fractions, clamped to 0..1 and in order (the whole axis when missing). @return array{0: float, 1: float} */
    private static function span(mixed $axis): array
    {
        if (!is_array($axis)) {
            return [0.0, 1.0];
        }
        $a = max(0.0, min(1.0, (float) ($axis[0] ?? 0)));
        $b = max(0.0, min(1.0, (float) ($axis[1] ?? 1)));

        return $a <= $b ? [$a, $b] : [$b, $a];
    }

    /**
     * How far along the area the anchor lies (0 at the start, 1 at the end) on each axis.
     *
     * @param array{x: float, y: float} $mark
     * @param array<mixed> $area
     * @return array{0: float, 1: float}
     */
    private static function offsets(array $mark, array $area): array
    {
        [$x0, $x1] = self::span($area['x'] ?? null);
        [$y0, $y1] = self::span($area['y'] ?? null);

        return [
            $x1 > $x0 ? max(0.0, min(1.0, ($mark['x'] - $x0) / ($x1 - $x0))) : 0.0,
            $y1 > $y0 ? max(0.0, min(1.0, ($mark['y'] - $y0) / ($y1 - $y0))) : 0.0,
        ];
    }

    private static function num(float $n): string
    {
        $s = rtrim(rtrim(sprintf('%.3F', $n), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }
}
