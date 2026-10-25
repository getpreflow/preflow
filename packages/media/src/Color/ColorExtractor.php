<?php

declare(strict_types=1);

namespace Preflow\Media\Color;

use Preflow\Media\Image\ColorManagement;

/**
 * An image's colours as people see them: the dominant colour, a palette of up to 8
 * colours that occur in the image (most significant first; fewer than 5 when the image
 * has fewer distinct colours, never made up) and the text colour (#ffffff or #000000, whichever
 * has the higher WCAG contrast on the dominant one).
 *
 * Read from a copy of at most 200 px: Imagick quantises it to 16 colours, or, without
 * Imagick, a GD histogram of 4096 bins does. Both are then merged into clusters of
 * similar colours (CIELAB distance), ranked by how many pixels they cover. Mostly
 * transparent pixels do not count.
 */
final class ColorExtractor
{
    /** The long side of the copy the colours are read from. */
    private const int SAMPLE = 200;

    private const int QUANTIZE = 16;

    private const int MAX_PALETTE = 8;

    /** Colours closer than this (CIE76 ΔE) are one cluster. */
    private const float MERGE = 12.0;

    private readonly bool $imagick;

    /** @param ?bool $imagick null: Imagick when the extension is loaded; a flag overrides it (tests) */
    public function __construct(
        private readonly ColorManagement $colors = new ColorManagement(),
        ?bool $imagick = null,
    ) {
        $this->imagick = $imagick ?? class_exists(\Imagick::class);
    }

    /**
     * The colours of any image file: one with a CMYK or non-sRGB profile is converted
     * to sRGB first. Null when it would need that and Imagick (with lcms) is missing
     * (no colours rather than wrong ones), and for an image without opaque pixels.
     *
     * @return ?array{color: string, palette: list<string>, text: string}
     * @throws \RuntimeException for a file that cannot be read as an image
     */
    public function fromFile(string $file): ?array
    {
        if (!$this->colors->needsConversion($file)) {
            return self::result($this->points($file));
        }
        if (!$this->colors->available()) {
            return null;
        }
        // Made small first (a box filter keeps the profile, which thumbnailImage() strips),
        // so a phone photo is not converted at full size; then converted to sRGB.
        $image = self::firstFrame(self::read($file));
        if ($image->getImageWidth() > self::SAMPLE || $image->getImageHeight() > self::SAMPLE) {
            $image->resizeImage(self::SAMPLE, self::SAMPLE, \Imagick::FILTER_BOX, 1, true);
        }
        $this->colors->toSrgb($image);

        return self::result($this->quantized($image));
    }

    /**
     * The colours of an sRGB (or untagged) image file, e.g. a variant source that
     * needs no conversion or a PDF's preview.
     *
     * @return array{color: string, palette: list<string>, text: string}
     * @throws \RuntimeException for a file that cannot be read as an image, or without opaque pixels
     */
    public function extract(string $srgbFile): array
    {
        return self::result($this->points($srgbFile)) ?? throw new \RuntimeException("{$srgbFile} has no opaque pixels.");
    }

    /**
     * An sRGB file's colours, counted.
     *
     * @return list<array{float, float, float, int}>
     */
    private function points(string $file): array
    {
        return $this->imagick ? $this->quantized(self::firstFrame(self::read($file))) : $this->histogram($file);
    }

    /** #ffffff or #000000, whichever has the higher WCAG contrast on $hex. */
    public static function textColor(string $hex): string
    {
        $luminance = 0.0;
        foreach (self::rgb($hex) as $i => $value) {
            $value /= 255;
            $linear = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            $luminance += [0.2126, 0.7152, 0.0722][$i] * $linear;
        }

        return (1.05 / ($luminance + 0.05)) >= (($luminance + 0.05) / 0.05) ? '#ffffff' : '#000000';
    }

    /**
     * @return array{int, int, int}
     */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /**
     * Imagick: the copy quantised to 16 colours, counted.
     *
     * @return list<array{float, float, float, int}> r, g, b, pixel count
     */
    private function quantized(\Imagick $image): array
    {
        if ($image->getImageWidth() > self::SAMPLE || $image->getImageHeight() > self::SAMPLE) {
            $image->thumbnailImage(self::SAMPLE, self::SAMPLE, true);
        }
        $image->quantizeImage(self::QUANTIZE, \Imagick::COLORSPACE_SRGB, 0, false, false);
        [$w, $h] = [$image->getImageWidth(), $image->getImageHeight()];
        $pixels = $image->exportImagePixels(0, 0, $w, $h, 'RGBA', \Imagick::PIXEL_CHAR);
        $image->clear();

        $counts = [];
        for ($i = 0, $n = count($pixels); $i < $n; $i += 4) {
            if ($pixels[$i + 3] < 128) {
                continue;
            }
            $key = ($pixels[$i] << 16) | ($pixels[$i + 1] << 8) | $pixels[$i + 2];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $points = [];
        foreach ($counts as $key => $count) {
            $points[] = [(float) ($key >> 16), (float) (($key >> 8) & 0xff), (float) ($key & 0xff), $count];
        }

        return $points;
    }

    /**
     * GD: a histogram of the copy in 16 levels per channel, each bin the average of
     * its pixels.
     *
     * @return list<array{float, float, float, int}> r, g, b, pixel count
     */
    private function histogram(string $file): array
    {
        $source = @imagecreatefromstring((string) @file_get_contents($file));
        if ($source === false) {
            throw new \RuntimeException("{$file} cannot be read as an image.");
        }
        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::SAMPLE / max($w, $h));
        [$sw, $sh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        $image = imagecreatetruecolor($sw, $sh);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $sw, $sh, $w, $h);
        unset($source);

        $bins = [];
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $c = imagecolorat($image, $x, $y);
                if ((($c >> 24) & 0x7f) > 63) { // GD alpha: 0 opaque, 127 transparent
                    continue;
                }
                [$r, $g, $b] = [($c >> 16) & 0xff, ($c >> 8) & 0xff, $c & 0xff];
                $key = (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4);
                $bins[$key] ??= [0, 0, 0, 0];
                $bins[$key][0] += $r;
                $bins[$key][1] += $g;
                $bins[$key][2] += $b;
                $bins[$key][3]++;
            }
        }

        return array_values(array_map(static fn (array $bin) => [$bin[0] / $bin[3], $bin[1] / $bin[3], $bin[2] / $bin[3], $bin[3]], $bins));
    }

    /**
     * Counted colours merged into clusters, most pixels first.
     *
     * @param list<array{float, float, float, int}> $points
     * @return ?array{color: string, palette: list<string>, text: string} null without points
     */
    private static function result(array $points): ?array
    {
        if ($points === []) {
            return null;
        }
        usort($points, static fn (array $a, array $b) => $b[3] <=> $a[3]);
        $clusters = []; // [sumR, sumG, sumB, count, lab of the first (largest) member]
        foreach ($points as [$r, $g, $b, $count]) {
            $lab = self::lab([$r, $g, $b]);
            foreach ($clusters as &$cluster) {
                if (self::distance($cluster[4], $lab) < self::MERGE) {
                    $cluster[0] += $r * $count;
                    $cluster[1] += $g * $count;
                    $cluster[2] += $b * $count;
                    $cluster[3] += $count;
                    continue 2;
                }
            }
            unset($cluster);
            $clusters[] = [$r * $count, $g * $count, $b * $count, $count, $lab];
        }
        unset($cluster);
        usort($clusters, static fn (array $a, array $b) => $b[3] <=> $a[3]);

        $palette = [];
        foreach ($clusters as [$r, $g, $b, $count]) {
            $hex = self::hex([$r / $count, $g / $count, $b / $count]);
            if (!in_array($hex, $palette, true)) {
                $palette[] = $hex;
            }
            if (count($palette) === self::MAX_PALETTE) {
                break;
            }
        }

        return ['color' => $palette[0], 'palette' => $palette, 'text' => self::textColor($palette[0])];
    }

    /** @param array<int|float> $rgb */
    private static function hex(array $rgb): string
    {
        return '#' . implode('', array_map(static fn (int|float $c) => sprintf('%02x', max(0, min(255, (int) round($c)))), $rgb));
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

    /**
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     */
    private static function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** Read by Imagick, a big JPEG decoded at a fraction of its size. */
    private static function read(string $file): \Imagick
    {
        try {
            $image = new \Imagick();
            $image->setOption('jpeg:size', (self::SAMPLE * 2) . 'x' . (self::SAMPLE * 2));
            $image->readImage($file);
        } catch (\ImagickException $e) {
            throw new \RuntimeException("{$file} cannot be read as an image: " . $e->getMessage(), 0, $e);
        }

        return $image;
    }

    /** An animation's first frame. */
    private static function firstFrame(\Imagick $image): \Imagick
    {
        if ($image->getNumberImages() <= 1) {
            return $image;
        }
        $image->setFirstIterator();
        $first = $image->getImage();
        $image->clear();

        return $first;
    }
}
