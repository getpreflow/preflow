<?php

declare(strict_types=1);

namespace Preflow\Media\Preview;

use Preflow\Media\Image\ColorManagement;

/**
 * A PDF's first page as an sRGB PNG, rendered by Ghostscript.
 *
 * Ghostscript, not Poppler's pdftocairo: on the CMYK reference PDF
 * (tests/Fixtures/pdf/cmyk-print.pdf, FOGRA39 output intent) Ghostscript with
 * -dUsePDFX3Profile, relative colorimetric intent and black-point compensation
 * matched every patch within ΔE2000 0.3 (cyan 0,159,227 as the reference).
 * pdftocairo and pdftoppm (Poppler 26.10) converted DeviceCMYK naively, ignoring
 * the output intent: cyan came out 0,173,239, the skin tone and grey ΔE 5-8 off.
 * Ghostscript takes the PDF's output intent when it has one, else the CMYK profile
 * of ColorManagement (FOGRA39L, or config color_profile_cmyk), and renders into
 * the shipped sRGB profile.
 *
 * Two passes: a tiny one that measures the page, then one at the resolution that
 * makes the long side at least $longSide; the result is scaled to exactly that,
 * stripped (Imagick through ColorManagement, else GD) and written as PNG.
 *
 * Ghostscript runs through proc_open with an argument list (no shell), -dSAFER,
 * fixed options, the asset's own path after -f and a temp output file; both passes
 * share one time limit, after which the process is killed. A broken, encrypted or
 * empty PDF makes no page: null. Without Ghostscript the renderer supports nothing.
 */
final class PdfPreviewRenderer implements PreviewRenderer
{
    /** Seconds both Ghostscript passes may take together. */
    public const int TIME_LIMIT = 30;

    /** Where Ghostscript is looked for after PATH. */
    private const array SEARCH_DIRS = ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/opt/local/bin'];

    /** The measuring pass: a 600 × 400 pt page is 150 × 100 px. */
    private const int MEASURE_DPI = 18;

    private bool $resolved = false;

    private ?string $binary = null;

    /**
     * @param ?string $ghostscript config "ghostscript": the gs binary; null looks it up on PATH and in the usual places
     * @param int     $timeLimit   seconds (tests shorten it)
     */
    public function __construct(
        private readonly ColorManagement $color = new ColorManagement(),
        private readonly ?string $ghostscript = null,
        private readonly int $timeLimit = self::TIME_LIMIT,
    ) {}

    public function supports(string $mime): bool
    {
        return $mime === 'application/pdf' && $this->binary() !== null;
    }

    public function render(string $file, int $longSide): ?string
    {
        $gs = $this->binary();
        if ($gs === null || $longSide < 1 || !is_file($file)) {
            return null;
        }
        $deadline = microtime(true) + $this->timeLimit;
        $measure = self::tempFile();
        $page = self::tempFile();
        $done = false;
        try {
            if (!$this->ghostscript($gs, $file, $measure, self::MEASURE_DPI, $deadline)) {
                return null;
            }
            $size = @getimagesize($measure);
            if (!is_array($size) || $size[0] < 1 || $size[1] < 1) {
                return null;
            }
            // One pixel less than measured, so the page is never rendered below the target and only scaled down.
            $dpi = $longSide * self::MEASURE_DPI / max(1, max($size[0], $size[1]) - 1);
            if (!$this->ghostscript($gs, $file, $page, $dpi, $deadline)) {
                return null;
            }

            $done = $this->finish($page, $longSide);

            return $done ? $page : null;
        } finally {
            @unlink($measure);
            if (!$done) {
                @unlink($page);
            }
        }
    }

    /** Ghostscript: the configured binary when it is executable, else the first on PATH or in the usual places. */
    public static function findGhostscript(?string $configured = null): ?string
    {
        if ($configured !== null) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }
        $dirs = [...explode(PATH_SEPARATOR, (string) getenv('PATH')), ...self::SEARCH_DIRS];
        foreach (array_unique(array_filter($dirs)) as $dir) {
            $candidate = rtrim($dir, '/') . '/gs';
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function binary(): ?string
    {
        if (!$this->resolved) {
            $this->binary = self::findGhostscript($this->ghostscript);
            $this->resolved = true;
        }

        return $this->binary;
    }

    /** The first page at $dpi into $out; false on failure, no page or when the deadline passes. */
    private function ghostscript(string $gs, string $file, string $out, float $dpi, float $deadline): bool
    {
        @unlink($out);
        $ok = self::run([
            $gs, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT',
            '-sDEVICE=png16m', '-dFirstPage=1', '-dLastPage=1', '-dUseCropBox',
            '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
            '-dUsePDFX3Profile', '-sDefaultCMYKProfile=' . $this->color->cmykProfile(),
            '-sOutputICCProfile=' . ColorManagement::SRGB_PROFILE, '-dRenderIntent=1', '-dBlackPtComp=1',
            '-r' . sprintf('%.3F', $dpi), '-sOutputFile=' . str_replace('%', '%%', $out), '-f', $file,
        ], $deadline);
        clearstatcache(true, $out);

        return $ok && is_file($out) && filesize($out) > 0;
    }

    /**
     * Run without a shell, output discarded, killed at the deadline.
     *
     * @param list<string> $command
     */
    private static function run(array $command, float $deadline): bool
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }
        while (($status = proc_get_status($process))['running']) {
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                proc_close($process);

                return false;
            }
            usleep(10_000);
        }
        proc_close($process);

        return $status['exitcode'] === 0;
    }

    /** Scale to exactly $longSide on the long side, in sRGB without a profile; rewritten in place. */
    private function finish(string $png, int $longSide): bool
    {
        $size = @getimagesize($png);
        if (!is_array($size) || $size[0] < 1 || $size[1] < 1) {
            return false;
        }
        [$w, $h] = $size[0] >= $size[1]
            ? [$longSide, max(1, (int) round($size[1] * $longSide / $size[0]))]
            : [max(1, (int) round($size[0] * $longSide / $size[1])), $longSide];

        if (class_exists(\Imagick::class)) {
            try {
                $image = $this->color->open($png);
                $this->color->toSrgb($image); // Ghostscript wrote sRGB: this strips its profile
                if ([$image->getImageWidth(), $image->getImageHeight()] !== [$w, $h]) {
                    $image->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);
                }
                $image->setImageFormat('png');
                $ok = $image->writeImage('png24:' . $png);
                $image->clear();

                return $ok;
            } catch (\ImagickException|\RuntimeException) {
                return false;
            }
        }
        if (function_exists('imagecreatefrompng')) {
            $image = @imagecreatefrompng($png);
            if ($image === false) {
                return false;
            }
            $scaled = [$size[0], $size[1]] === [$w, $h] ? $image : imagescale($image, $w, $h, IMG_BICUBIC);

            return $scaled !== false && imagepng($scaled, $png); // re-encoded: the profile is gone
        }

        return [$size[0], $size[1]] === [$w, $h];
    }

    private static function tempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'preflow-preview-');
        if ($file === false) {
            throw new \RuntimeException('No temp file for a preview.');
        }

        return $file;
    }
}
