<?php

declare(strict_types=1);

/*
 * Makes the colour fixtures and their reference patch values (references.json).
 * Run by hand, from anywhere, when a fixture or the conversion settings change:
 *
 *     php packages/media/tests/Fixtures/color/make-references.php
 *
 * Needs the ImageMagick command line (`magick`, 7.x with lcms). It does not use the
 * package's own code: the references come from ImageMagick's CLI with the settings
 * the package promises (relative colorimetric intent, black-point compensation,
 * sRGB out), so a test against them checks ColorManagement, not itself.
 */

$here = __DIR__;
$icc = dirname(__DIR__, 3) . '/resources/icc';
$cmykProfile = $icc . '/FOGRA39L_coated.icc';
$srgbProfile = $icc . '/sRGB.icc';
$adobeProfile = $here . '/AdobeRGB1998.icc';

const PATCH = 32;
const COLUMNS = 4;
const ROWS = 2;

// Patch values, row by row. CMYK in percent: paper, cyan, magenta, yellow, a
// rich black (black-point compensation shows here), red, a skin tone, a mid grey.
$cmyk = [
    [0, 0, 0, 0], [100, 0, 0, 0], [0, 100, 0, 0], [0, 0, 100, 0],
    [60, 50, 50, 100], [0, 100, 100, 0], [5, 30, 40, 0], [40, 30, 30, 10],
];
// RGB 0-255: white, black, a saturated green (outside sRGB in Adobe RGB), a red,
// a mid grey, a blue, a warm yellow, a light blue.
$rgb = [
    [255, 255, 255], [0, 0, 0], [0, 200, 0], [200, 50, 50],
    [128, 128, 128], [30, 60, 200], [240, 200, 100], [100, 180, 220],
];

/** @param list<string> $args */
function run(array $args): string
{
    $command = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($command, $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "failed: {$command}\n" . implode("\n", $out) . "\n");
        exit(1);
    }

    return implode("\n", $out);
}

/** @param list<string> $colors ImageMagick colour strings, row by row */
function grid(array $colors): array
{
    $args = [];
    foreach (array_chunk($colors, COLUMNS) as $row) {
        $args[] = '(';
        foreach ($row as $color) {
            $args = [...$args, '-size', PATCH . 'x' . PATCH, 'xc:' . $color];
        }
        $args = [...$args, '+append', ')'];
    }

    return [...$args, '-append'];
}

/** @return list<array{0: float, 1: float}> patch centres as fractions of width and height */
function centres(): array
{
    $out = [];
    for ($r = 0; $r < ROWS; $r++) {
        for ($c = 0; $c < COLUMNS; $c++) {
            $out[] = [($c + 0.5) / COLUMNS, ($r + 0.5) / ROWS];
        }
    }

    return $out;
}

/**
 * The file converted to sRGB by the CLI, sampled at the patch centres (0-255, rounded).
 *
 * @param list<string> $assign a profile to assign first (an untagged CMYK file)
 * @return list<list<int>>
 */
function reference(string $file, string $srgbProfile, array $assign = []): array
{
    $values = [];
    foreach (centres() as [$x, $y]) {
        $px = (int) ($x * PATCH * COLUMNS);
        $py = (int) ($y * PATCH * ROWS);
        $out = run(['magick', $file, '-intent', 'relative', '-black-point-compensation', ...$assign, '-profile', $srgbProfile, '-crop', "1x1+{$px}+{$py}", '-depth', '16', '-format', '%[fx:r*255],%[fx:g*255],%[fx:b*255]', 'info:']);
        $values[] = array_map(static fn (string $v): int => max(0, min(255, (int) round((float) $v))), explode(',', trim($out)));
    }

    return $values;
}

$cmykColors = array_map(static fn (array $v): string => vsprintf('cmyk(%d%%,%d%%,%d%%,%d%%)', $v), $cmyk);
$rgbColors = array_map(static fn (array $v): string => vsprintf('rgb(%d,%d,%d)', $v), $rgb);
$jpeg = ['-quality', '95', '-sampling-factor', '1x1'];

// A CMYK JPEG with FOGRA39 embedded, and the same pixels without a profile.
run(['magick', ...grid($cmykColors), '-profile', $cmykProfile, ...$jpeg, $here . '/cmyk-profile.jpg']);
run(['magick', $here . '/cmyk-profile.jpg', '+profile', '*', ...$jpeg, $here . '/cmyk-no-profile.jpg']);
// The RGB values tagged as Adobe RGB (assigned, not converted), and tagged as sRGB.
run(['magick', ...grid($rgbColors), '-profile', $adobeProfile, ...$jpeg, $here . '/adobe-rgb.jpg']);
run(['magick', ...grid($rgbColors), '-profile', $srgbProfile, ...$jpeg, $here . '/srgb.jpg']);

$references = [
    'about' => 'Patch values in sRGB (0-255) of each fixture, converted by the ImageMagick CLI with relative colorimetric intent and black-point compensation. Made by make-references.php.',
    'patch' => PATCH,
    'columns' => COLUMNS,
    'rows' => ROWS,
    'centres' => centres(),
    'files' => [
        'cmyk-profile.jpg' => ['source' => $cmyk, 'srgb' => reference($here . '/cmyk-profile.jpg', $srgbProfile)],
        // Untagged CMYK is read as the default profile, so its references are FOGRA39's.
        'cmyk-no-profile.jpg' => ['source' => $cmyk, 'srgb' => reference($here . '/cmyk-no-profile.jpg', $srgbProfile, ['-profile', $cmykProfile])],
        'adobe-rgb.jpg' => ['source' => $rgb, 'srgb' => reference($here . '/adobe-rgb.jpg', $srgbProfile)],
        'srgb.jpg' => ['source' => $rgb, 'srgb' => reference($here . '/srgb.jpg', $srgbProfile)],
    ],
];

file_put_contents($here . '/references.json', json_encode($references, JSON_PRETTY_PRINT) . "\n");
echo "Wrote the fixtures and references.json in {$here}\n";
