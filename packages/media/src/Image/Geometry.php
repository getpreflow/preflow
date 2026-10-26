<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

use Preflow\Media\CropRect;

/** Pixel arithmetic for box formats, with no image in sight. */
final class Geometry
{
    /**
     * The largest rectangle of a width/height ratio inside the source, centred on the focal point
     * (percent of the source) and moved back inside at the edges.
     *
     * @return array{x: int, y: int, w: int, h: int}
     */
    public static function focalBox(int $srcW, int $srcH, float $ratio, int $focalX, int $focalY): array
    {
        [$w, $h] = self::largest($srcW, $srcH, $ratio);

        return [
            'x' => max(0, min($srcW - $w, (int) round($focalX / 100 * $srcW - $w / 2))),
            'y' => max(0, min($srcH - $h, (int) round($focalY / 100 * $srcH - $h / 2))),
            'w' => $w,
            'h' => $h,
        ];
    }

    /**
     * The largest rectangle of the ratio inside the region, centred in it.
     *
     * @param array{x: int, y: int, w: int, h: int} $region
     * @return array{x: int, y: int, w: int, h: int}
     */
    public static function fitRatio(array $region, float $ratio): array
    {
        [$w, $h] = self::largest($region['w'], $region['h'], $ratio);

        return [
            'x' => $region['x'] + (int) round(($region['w'] - $w) / 2),
            'y' => $region['y'] + (int) round(($region['h'] - $h) / 2),
            'w' => $w,
            'h' => $h,
        ];
    }

    /** @return array{int, int} the source scaled down to fit the box; never larger than the source */
    public static function containSize(int $srcW, int $srcH, int $boxW, int $boxH): array
    {
        $scale = min($boxW / max(1, $srcW), $boxH / max(1, $srcH), 1.0);

        return [max(1, (int) round($srcW * $scale)), max(1, (int) round($srcH * $scale))];
    }

    /**
     * Carries a focal point from percent of one crop to percent of another (null: the whole image),
     * keeping the same picture point; clamped to 0-100.
     *
     * @return array{int, int}
     */
    public static function focalAfter(int $focalX, int $focalY, ?CropRect $from, ?CropRect $to): array
    {
        $x = $from !== null ? $from->x + $focalX / 100 * $from->width : (float) $focalX;
        $y = $from !== null ? $from->y + $focalY / 100 * $from->height : (float) $focalY;
        if ($to !== null) {
            $x = ($x - $to->x) / $to->width * 100;
            $y = ($y - $to->y) / $to->height * 100;
        }

        return [max(0, min(100, (int) round($x))), max(0, min(100, (int) round($y)))];
    }

    /**
     * A point (percent of the image) after the image turned clockwise by quarter turns
     * (negative: counter-clockwise). It works for a point in percent of a crop too, as the
     * crop turns with the image.
     *
     * @return array{int, int}
     */
    public static function pointRotated(int $x, int $y, int $quarterTurns): array
    {
        for ($i = (($quarterTurns % 4) + 4) % 4; $i > 0; $i--) {
            [$x, $y] = [100 - $y, $x];
        }

        return [$x, $y];
    }

    /**
     * A point (percent of the image, or of a crop mirrored with it) after the image was
     * mirrored left-right, or top-bottom when not horizontal.
     *
     * @return array{int, int}
     */
    public static function pointMirrored(int $x, int $y, bool $horizontal): array
    {
        return $horizontal ? [100 - $x, $y] : [$x, 100 - $y];
    }

    /** @return array{int, int} */
    private static function largest(int $width, int $height, float $ratio): array
    {
        if ($width >= $height * $ratio) {
            return [max(1, min($width, (int) round($height * $ratio))), max(1, $height)];
        }

        return [max(1, $width), max(1, min($height, (int) round($width / $ratio)))];
    }
}
