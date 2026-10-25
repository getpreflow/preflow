<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Support;

/** CIEDE2000 between two sRGB colours, for comparing rendered patches with their references. */
trait DeltaE
{
    /**
     * CIEDE2000 between two sRGB colours (0-255).
     *
     * @param array<int|float> $a
     * @param array<int|float> $b
     */
    private static function deltaE2000(array $a, array $b): float
    {
        [$l1, $a1, $b1] = self::lab($a);
        [$l2, $a2, $b2] = self::lab($b);
        $c1 = hypot($a1, $b1);
        $c2 = hypot($a2, $b2);
        $cBar = ($c1 + $c2) / 2;
        $g = 0.5 * (1 - sqrt($cBar ** 7 / ($cBar ** 7 + 25 ** 7)));
        $a1p = (1 + $g) * $a1;
        $a2p = (1 + $g) * $a2;
        $c1p = hypot($a1p, $b1);
        $c2p = hypot($a2p, $b2);
        $h1p = $c1p == 0 ? 0.0 : fmod(rad2deg(atan2($b1, $a1p)) + 360, 360);
        $h2p = $c2p == 0 ? 0.0 : fmod(rad2deg(atan2($b2, $a2p)) + 360, 360);
        $dLp = $l2 - $l1;
        $dCp = $c2p - $c1p;
        $dhp = 0.0;
        if ($c1p * $c2p != 0) {
            $dhp = $h2p - $h1p;
            $dhp += $dhp > 180 ? -360 : ($dhp < -180 ? 360 : 0);
        }
        $dHp = 2 * sqrt($c1p * $c2p) * sin(deg2rad($dhp / 2));
        $lBarP = ($l1 + $l2) / 2;
        $cBarP = ($c1p + $c2p) / 2;
        $hBarP = $h1p + $h2p;
        if ($c1p * $c2p != 0) {
            $hBarP = abs($h1p - $h2p) > 180 ? ($h1p + $h2p + ($h1p + $h2p < 360 ? 360 : -360)) / 2 : ($h1p + $h2p) / 2;
        }
        $t = 1 - 0.17 * cos(deg2rad($hBarP - 30)) + 0.24 * cos(deg2rad(2 * $hBarP)) + 0.32 * cos(deg2rad(3 * $hBarP + 6)) - 0.20 * cos(deg2rad(4 * $hBarP - 63));
        $dTheta = 30 * exp(-((($hBarP - 275) / 25) ** 2));
        $rc = 2 * sqrt($cBarP ** 7 / ($cBarP ** 7 + 25 ** 7));
        $sl = 1 + (0.015 * ($lBarP - 50) ** 2) / sqrt(20 + ($lBarP - 50) ** 2);
        $sc = 1 + 0.045 * $cBarP;
        $sh = 1 + 0.015 * $cBarP * $t;
        $rt = -sin(deg2rad(2 * $dTheta)) * $rc;

        return sqrt(($dLp / $sl) ** 2 + ($dCp / $sc) ** 2 + ($dHp / $sh) ** 2 + $rt * ($dCp / $sc) * ($dHp / $sh));
    }

    /**
     * @param array<int|float> $rgb sRGB 0-255
     * @return array{float, float, float} CIELAB (D65)
     */
    private static function lab(array $rgb): array
    {
        $lin = array_map(static function (int|float $v): float {
            $v /= 255;

            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, array_values($rgb));
        $x = (0.4124564 * $lin[0] + 0.3575761 * $lin[1] + 0.1804375 * $lin[2]) / 0.95047;
        $y = 0.2126729 * $lin[0] + 0.7151522 * $lin[1] + 0.0721750 * $lin[2];
        $z = (0.0193339 * $lin[0] + 0.1191920 * $lin[1] + 0.9503041 * $lin[2]) / 1.08883;
        $f = static fn (float $t): float => $t > 216 / 24389 ? $t ** (1 / 3) : (24389 / 27 * $t + 16) / 116;

        return [116 * $f($y) - 16, 500 * ($f($x) - $f($y)), 200 * ($f($y) - $f($z))];
    }
}
