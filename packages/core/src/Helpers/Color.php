<?php

declare(strict_types=1);

namespace Preflow\Core\Helpers;

final class Color
{
    /** @return array{int, int, int} [r, g, b] */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    public static function lighten(string $hex, float $percent): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $r = (int) round(min(255, $r + ($percent * (255 - $r))));
        $g = (int) round(min(255, $g + ($percent * (255 - $g))));
        $b = (int) round(min(255, $b + ($percent * (255 - $b))));
        return self::rgbToHex($r, $g, $b);
    }

    public static function darken(string $hex, float $percent): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $r = (int) round(max(0, $r - ($percent * $r)));
        $g = (int) round(max(0, $g - ($percent * $g)));
        $b = (int) round(max(0, $b - ($percent * $b)));
        return self::rgbToHex($r, $g, $b);
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $components = [];
        foreach ([$r, $g, $b] as $c) {
            $c = $c / 255;
            $components[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $components[0] + 0.7152 * $components[1] + 0.0722 * $components[2];
    }

    public static function contrastRatio(string $color1, string $color2): float
    {
        $l1 = self::luminance($color1);
        $l2 = self::luminance($color2);
        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function adjustForContrast(string $textColor, string $backgroundColor = '#ffffff', float $minContrast = 4.5): string
    {
        if (self::contrastRatio($textColor, $backgroundColor) >= $minContrast) {
            return $textColor;
        }
        $bgLuminance = self::luminance($backgroundColor);
        $shouldDarken = $bgLuminance > 0.5;
        $step = 0.05;
        for ($i = 0; $i < 20; $i++) {
            $textColor = $shouldDarken ? self::darken($textColor, $step) : self::lighten($textColor, $step);
            if (self::contrastRatio($textColor, $backgroundColor) >= $minContrast) {
                break;
            }
        }
        return $textColor;
    }
}
