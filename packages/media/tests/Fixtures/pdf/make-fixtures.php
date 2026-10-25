<?php

declare(strict_types=1);

/*
 * Makes the PDF fixtures and the CMYK reference patch values (references.json).
 * Run by hand, from anywhere, when a fixture changes:
 *
 *     php packages/media/tests/Fixtures/pdf/make-fixtures.php
 *
 * The PDFs are written by this script, byte for byte. The encrypted one is made by
 * Ghostscript (`gs`, pdfwrite with a user password) and the references by the
 * ImageMagick command line (`magick`, 7.x with lcms), not by the package's own code:
 * the CMYK patches converted from FOGRA39L into sRGB with relative colorimetric
 * intent and black-point compensation, the settings the package promises.
 */

$here = __DIR__;
$icc = dirname(__DIR__, 3) . '/resources/icc';
$cmykProfile = $icc . '/FOGRA39L_coated.icc';
$srgbProfile = $icc . '/sRGB.icc';

const COLUMNS = 4;
const ROWS = 2;

// The patches of tests/Fixtures/color (CMYK in percent): paper, cyan, magenta, yellow,
// a rich black, red, a skin tone, a mid grey.
$cmyk = [
    [0, 0, 0, 0], [100, 0, 0, 0], [0, 100, 0, 0], [0, 0, 100, 0],
    [60, 50, 50, 100], [0, 100, 100, 0], [5, 30, 40, 0], [40, 30, 30, 10],
];
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

/**
 * A PDF of the given objects (1 is the catalog), with a correct xref table.
 *
 * @param list<string> $objects object bodies, numbered from 1
 */
function pdf(array $objects): string
{
    $out = "%PDF-1.6\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($out);
        $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($out);
    $out .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $out .= sprintf("%010d 00000 n \n", $offset);
    }

    return $out . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

function stream(string $data, string $dict = ''): string
{
    $packed = (string) gzcompress($data, 9);

    return '<< ' . $dict . ' /Filter /FlateDecode /Length ' . strlen($packed) . " >>\nstream\n" . $packed . "\nendstream";
}

/**
 * Patches filling a page of $width × $height points, four columns and two rows, row by row from the top.
 *
 * @param list<string> $fills the fill operator of each patch ("c m y k k" or "r g b rg")
 */
function patches(array $fills, int $width, int $height): string
{
    $w = $width / COLUMNS;
    $h = $height / ROWS;
    $ops = '';
    foreach ($fills as $i => $fill) {
        $x = ($i % COLUMNS) * $w;
        $y = $height - (intdiv($i, COLUMNS) + 1) * $h;
        $ops .= "{$fill}\n{$x} {$y} {$w} {$h} re f\n";
    }

    return $ops;
}

$f = static fn (int $v): string => rtrim(rtrim(sprintf('%.4f', $v / 100), '0'), '.');
$cmykFills = array_map(static fn (array $c): string => implode(' ', array_map($f, $c)) . ' k', $cmyk);
$rgbFills = array_map(static fn (array $c): string => implode(' ', array_map(static fn (int $v) => rtrim(rtrim(sprintf('%.4f', $v / 255), '0'), '.'), $c)) . ' rg', $rgb);

// RGB, 600 × 400 pt landscape: a 1600 px long side makes 1600 × 1067.
file_put_contents($here . '/rgb.pdf', pdf([
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 400] /Contents 4 0 R /Resources << >> >>',
    stream(patches($rgbFills, 600, 400)),
]));

// CMYK for print, 400 × 200 pt: DeviceCMYK patches and a PDF/X output intent carrying FOGRA39L.
file_put_contents($here . '/cmyk-print.pdf', pdf([
    '<< /Type /Catalog /Pages 2 0 R /OutputIntents [5 0 R] >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 200] /TrimBox [0 0 400 200] /Contents 4 0 R /Resources << >> >>',
    stream(patches($cmykFills, 400, 200)),
    '<< /Type /OutputIntent /S /GTS_PDFX /OutputConditionIdentifier (FOGRA39) /Info (Coated FOGRA39) /RegistryName (http://www.color.org) /DestOutputProfile 6 0 R >>',
    stream((string) file_get_contents($cmykProfile), '/N 4'),
]));

// Broken: a PDF header, then the file stops in the middle of an object.
file_put_contents($here . '/broken.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R\n2 0 obj << /Kids [3 0");

// Encrypted with a user password: the first page cannot be read without it.
run(['gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-sOwnerPassword=owner-secret', '-sUserPassword=user-secret', '-dEncryptionR=3', '-dKeyLength=128', '-dPermissions=-4', '-o', $here . '/encrypted.pdf', $here . '/rgb.pdf']);

// References: each CMYK patch converted by the CLI, from FOGRA39L into sRGB.
$references = [];
foreach ($cmyk as $c) {
    $out = run(['magick', '-size', '1x1', 'xc:' . vsprintf('cmyk(%d%%,%d%%,%d%%,%d%%)', $c), '-profile', $cmykProfile, '-intent', 'relative', '-black-point-compensation', '-profile', $srgbProfile, '-depth', '16', '-format', '%[fx:r*255],%[fx:g*255],%[fx:b*255]', 'info:']);
    $references[] = array_map(static fn (string $v): int => max(0, min(255, (int) round((float) $v))), explode(',', trim($out)));
}
$centres = [];
for ($r = 0; $r < ROWS; $r++) {
    for ($c = 0; $c < COLUMNS; $c++) {
        $centres[] = [($c + 0.5) / COLUMNS, ($r + 0.5) / ROWS];
    }
}

file_put_contents($here . '/references.json', json_encode([
    'about' => 'cmyk-print.pdf: its CMYK patches in sRGB (0-255), converted by the ImageMagick CLI from FOGRA39L with relative colorimetric intent and black-point compensation. Made by make-fixtures.php.',
    'columns' => COLUMNS,
    'rows' => ROWS,
    'centres' => $centres,
    'cmyk' => $cmyk,
    'srgb' => $references,
], JSON_PRETTY_PRINT) . "\n");
echo "Wrote the PDF fixtures and references.json in {$here}\n";
