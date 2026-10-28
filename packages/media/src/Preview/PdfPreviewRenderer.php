<?php

declare(strict_types=1);

namespace Preflow\Media\Preview;

use Preflow\Media\Image\ColorManagement;

/**
 * A PDF's first page as an sRGB PNG, by Ghostscript when a safe one is installed,
 * else by Poppler's pdftocairo.
 *
 * Ghostscript is the faithful engine: on the CMYK reference PDF
 * (tests/Fixtures/pdf/cmyk-print.pdf, FOGRA39 output intent) Ghostscript with
 * -dUsePDFX3Profile, relative colorimetric intent and black-point compensation
 * matched every patch within ΔE2000 0.3 (cyan 0,159,227 as the reference), and it
 * follows another output intent too (cmyk-newsprint.pdf, SNAP newsprint). Poppler
 * (pdftocairo and pdftoppm 26.10) converts DeviceCMYK naively and ignores the output
 * intent: cyan came out 0,173,239, the skin tone and grey ΔE 5-8 off. Ghostscript
 * takes the PDF's output intent when it has one, else the CMYK profile of
 * ColorManagement (FOGRA39L, or config color_profile_cmyk), and renders into the
 * shipped sRGB profile.
 *
 * But Ghostscript runs PostScript, and versions before GHOSTSCRIPT_FLOOR have
 * published -dSAFER escapes (CVE-2019-10216, CVE-2019-14811 to 14817, CVE-2024-29510).
 * An older one (servers with 9.x) is never run on uploaded files: then pdftocairo,
 * which executes no PostScript, makes the preview, with Poppler's colours for CMYK
 * (the output intent ignored). Neither usable: the renderer supports nothing.
 *
 * Ghostscript makes two passes: a tiny one that measures the page, then one at the
 * resolution that makes the long side at least $longSide. A page so large that the
 * measuring pass would be over MEASURE_MAX pixels (its size from -dPDFINFO, with
 * /UserUnit and /Rotate) is not measured by drawing: it is fitted straight into a
 * bitmap of $longSide (-dFIXEDMEDIA -dPDFFitPage), so it never costs a huge bitmap
 * or the time limit. pdftocairo scales to the
 * long side itself. The result is scaled to exactly $longSide, converted to sRGB and
 * stripped (Imagick through ColorManagement, else GD) and written as PNG.
 *
 * Both run through proc_open with an argument list (no shell): fixed options, the
 * asset's own path and temp output files; all passes share one time limit, after which
 * the process is killed. A broken, encrypted or empty PDF makes no page: null.
 */
final class PdfPreviewRenderer implements PreviewRenderer
{
    /** Seconds the passes of one preview may take together. */
    public const int TIME_LIMIT = 30;

    /** The oldest Ghostscript run on uploaded files (CVE-2024-29510 is fixed in it). */
    public const string GHOSTSCRIPT_FLOOR = '10.03.1';

    public const string GHOSTSCRIPT = 'ghostscript';
    public const string PDFTOCAIRO = 'pdftocairo';

    /** Where the binaries are looked for after PATH. */
    private const array SEARCH_DIRS = ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/opt/local/bin'];

    /** The measuring pass: a 600 × 400 pt page is 150 × 100 px. */
    private const int MEASURE_DPI = 18;

    /** The measuring pass's long side at most: larger pages (over 8000 pt) are fitted instead. */
    private const int MEASURE_MAX = 2000;

    /** Seconds `gs --version` may take. */
    private const int VERSION_LIMIT = 5;

    /** @var array<string, ?string> Ghostscript versions by binary, read once per process */
    private static array $versions = [];

    /** @var array{0: string, 1: string}|false|null [engine, binary]; false: none; null: not looked up yet */
    private array|false|null $engine = null;

    /**
     * @param ?string $ghostscript config "ghostscript": the gs binary; null looks it up on PATH and in the usual places
     * @param int     $timeLimit   seconds (tests shorten it)
     * @param ?string $pdftocairo  config "pdftocairo": the fallback's binary; null looks it up like gs
     */
    public function __construct(
        private readonly ColorManagement $color = new ColorManagement(),
        private readonly ?string $ghostscript = null,
        private readonly int $timeLimit = self::TIME_LIMIT,
        private readonly ?string $pdftocairo = null,
    ) {}

    public function supports(string $mime): bool
    {
        return $mime === 'application/pdf' && $this->engine() !== null;
    }

    /** The engine previews are made with: GHOSTSCRIPT, PDFTOCAIRO, or null when neither is usable. */
    public function engine(): ?string
    {
        return $this->resolve()[0] ?? null;
    }

    public function render(string $file, int $longSide): ?string
    {
        $engine = $this->resolve();
        if ($engine === null || $longSide < 1 || !is_file($file)) {
            return null;
        }
        [$name, $binary] = $engine;
        $deadline = microtime(true) + $this->timeLimit;
        $temps = [];
        $page = null;
        try {
            $page = $name === self::GHOSTSCRIPT
                ? $this->viaGhostscript($binary, $file, $longSide, $deadline, $temps)
                : $this->viaPdftocairo($binary, $file, $longSide, $deadline, $temps);
            if ($page !== null && !$this->finish($page, $longSide)) {
                $page = null;
            }

            return $page;
        } finally {
            foreach ($temps as $temp) {
                if ($temp !== $page) {
                    @unlink($temp);
                }
            }
        }
    }

    /** A binary: the configured one when it is executable, else the first $name on PATH or in the usual places. */
    public static function findBinary(string $name, ?string $configured = null): ?string
    {
        if ($configured !== null) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }
        $dirs = [...explode(PATH_SEPARATOR, (string) getenv('PATH')), ...self::SEARCH_DIRS];
        foreach (array_unique(array_filter($dirs)) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $name;
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The version `gs --version` prints (cached per process); null when it cannot be read. */
    public static function ghostscriptVersion(string $gs): ?string
    {
        if (!array_key_exists($gs, self::$versions)) {
            $out = self::run([$gs, '--version'], microtime(true) + self::VERSION_LIMIT, capture: true);
            self::$versions[$gs] = $out !== null && preg_match('/^\s*(\d+\.\d+(?:\.\d+)?)\s*$/', $out, $m) === 1 ? $m[1] : null;
        }

        return self::$versions[$gs];
    }

    /** @return array{0: string, 1: string}|null */
    private function resolve(): ?array
    {
        if ($this->engine === null) {
            $gs = self::findBinary('gs', $this->ghostscript);
            $version = $gs !== null ? self::ghostscriptVersion($gs) : null;
            $cairo = self::findBinary('pdftocairo', $this->pdftocairo);
            $this->engine = match (true) {
                $version !== null && version_compare($version, self::GHOSTSCRIPT_FLOOR, '>=') => [self::GHOSTSCRIPT, (string) $gs],
                $cairo !== null => [self::PDFTOCAIRO, $cairo],
                default => false,
            };
        }

        return $this->engine === false ? null : $this->engine;
    }

    /**
     * @param list<string> $temps every temp file made, removed by render()
     */
    private function viaGhostscript(string $gs, string $file, int $longSide, float $deadline, array &$temps): ?string
    {
        $points = self::pageSize($gs, $file, $deadline);
        if ($points !== null && max($points) * self::MEASURE_DPI / 72 > self::MEASURE_MAX) {
            [$w, $h] = $points[0] >= $points[1]
                ? [$longSide, max(1, (int) round($points[1] * $longSide / $points[0]))]
                : [max(1, (int) round($points[0] * $longSide / $points[1])), $longSide];
            $temps[] = $page = self::tempFile();

            return $this->ghostscript($gs, $file, $page, 72, $deadline, ['-dFIXEDMEDIA', '-dPDFFitPage', "-g{$w}x{$h}"]) ? $page : null;
        }
        $temps[] = $measure = self::tempFile();
        if (!$this->ghostscript($gs, $file, $measure, self::MEASURE_DPI, $deadline)) {
            return null;
        }
        $size = @getimagesize($measure);
        if (!is_array($size) || $size[0] < 1 || $size[1] < 1) {
            return null;
        }
        // One pixel less than measured, so the page is never rendered below the target and only scaled down.
        $dpi = $longSide * self::MEASURE_DPI / max(1, max($size[0], $size[1]) - 1);
        $temps[] = $page = self::tempFile();

        return $this->ghostscript($gs, $file, $page, $dpi, $deadline) ? $page : null;
    }

    /**
     * The first page's width and height in points as shown (its CropBox, else MediaBox,
     * times /UserUnit, swapped by a /Rotate of 90 or 270), as Ghostscript's -dPDFINFO
     * prints them; null when it prints none.
     *
     * @return ?array{0: float, 1: float}
     */
    private static function pageSize(string $gs, string $file, float $deadline): ?array
    {
        $info = self::run([$gs, '-q', '-dNODISPLAY', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dPDFINFO', '-dFirstPage=1', '-dLastPage=1', $file], $deadline, capture: true);
        if ($info === null || preg_match('/^Page 1\b(.*)$/m', $info, $line) !== 1) {
            return null;
        }
        $box = '\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]';
        if (preg_match("/CropBox: {$box}/", $line[1], $b) !== 1 && preg_match("/MediaBox: {$box}/", $line[1], $b) !== 1) {
            return null;
        }
        $unit = preg_match('/UserUnit: ([\d.]+)/', $line[1], $u) === 1 && (float) $u[1] > 0 ? (float) $u[1] : 1.0;
        $size = [abs((float) $b[3] - (float) $b[1]) * $unit, abs((float) $b[4] - (float) $b[2]) * $unit];
        if (preg_match('/Rotate = (-?\d+)/', $line[1], $r) === 1 && abs((int) $r[1]) % 180 === 90) {
            $size = [$size[1], $size[0]];
        }

        return $size[0] > 0 && $size[1] > 0 ? $size : null;
    }

    /**
     * The first page at $dpi into $out (a temp file, empty until Ghostscript writes it); false on
     * failure, no page or at the deadline. $fit: options that fit the page into a fixed size.
     *
     * @param list<string> $fit
     */
    private function ghostscript(string $gs, string $file, string $out, float $dpi, float $deadline, array $fit = []): bool
    {
        $ok = self::run([
            $gs, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT',
            '-sDEVICE=png16m', '-dFirstPage=1', '-dLastPage=1', '-dUseCropBox',
            '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
            '-dUsePDFX3Profile', '-sDefaultCMYKProfile=' . $this->color->cmykProfile(),
            '-sOutputICCProfile=' . ColorManagement::SRGB_PROFILE, '-dRenderIntent=1', '-dBlackPtComp=1',
            '-r' . sprintf('%.3F', $dpi), ...$fit, '-sOutputFile=' . str_replace('%', '%%', $out), '-f', $file,
        ], $deadline) !== null;
        clearstatcache(true, $out);

        return $ok && is_file($out) && filesize($out) > 0;
    }

    /**
     * pdftocairo writes {root}.png; {root} is a temp file of its own, so the name is ours.
     *
     * @param list<string> $temps
     */
    private function viaPdftocairo(string $cairo, string $file, int $longSide, float $deadline, array &$temps): ?string
    {
        $temps[] = $root = self::tempFile();
        $temps[] = $page = $root . '.png';
        $ok = self::run([$cairo, '-png', '-singlefile', '-f', '1', '-l', '1', '-cropbox', '-scale-to', (string) $longSide, $file, $root], $deadline) !== null;
        clearstatcache(true, $page);

        return $ok && is_file($page) && filesize($page) > 0 ? $page : null;
    }

    /**
     * Run without a shell, stderr discarded, killed at the deadline. Returns stdout
     * ('' unless $capture) on exit code 0, else null. Shared with the other renderers
     * (VideoPreviewRenderer), like findBinary().
     *
     * @param list<string> $command
     */
    public static function run(array $command, float $deadline, bool $capture = false): ?string
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => $capture ? ['pipe', 'w'] : ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $out = '';
        if ($capture) {
            stream_set_blocking($pipes[1], false);
        }
        while (($status = proc_get_status($process))['running']) {
            if ($capture) {
                $out .= (string) stream_get_contents($pipes[1]);
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                if ($capture) {
                    fclose($pipes[1]);
                }
                proc_close($process);

                return null;
            }
            usleep(10_000);
        }
        if ($capture) {
            $out .= (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        proc_close($process);

        return $status['exitcode'] === 0 ? $out : null;
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

    /** An empty temp file for a renderer's output (the caller removes or hands it on). */
    public static function tempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'preflow-preview-');
        if ($file === false) {
            throw new \RuntimeException('No temp file for a preview.');
        }

        return $file;
    }
}
