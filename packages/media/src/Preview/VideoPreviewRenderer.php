<?php

declare(strict_types=1);

namespace Preflow\Media\Preview;

/**
 * One frame of a video (MP4, WebM, QuickTime) as a PNG, by ffmpeg: at 1 s, or at
 * 10 % of the length for a video shorter than 10 s, so a short clip does not show
 * its fade-in or its last frame. The length comes from ffprobe; without ffprobe, or
 * when it cannot read the file, the frame at 1 s. When no frame comes out at that
 * time (a clip shorter than the guess), the first frame.
 *
 * ffmpeg scales the frame so its long side is exactly $longSide (non-square pixels
 * made square first, a phone video's rotation applied by ffmpeg) and writes it as
 * PNG; it converts the video's colours to RGB itself, without a profile.
 *
 * Both run through PdfPreviewRenderer::run() with an argument list (no shell): fixed
 * options, the asset's own path (read through the file protocol only, so a crafted
 * file cannot make ffmpeg fetch anything) and a temp output file; all passes share
 * one time limit, after which the process is killed. Without ffmpeg the renderer
 * supports nothing; a broken file, a failing ffmpeg or the time limit give null.
 * An upload never fails because of either.
 */
final class VideoPreviewRenderer implements PreviewRenderer
{
    /** Seconds the passes of one preview (ffprobe, ffmpeg) may take together. */
    public const int TIME_LIMIT = 20;

    public const array MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    /** Videos shorter than this (seconds) take their frame at SHORT_FRACTION of their length. */
    private const float SHORT = 10.0;

    private const float SHORT_FRACTION = 0.1;

    /** The frame's time in longer videos, and without a known length. */
    private const float DEFAULT_SEEK = 1.0;

    /** @var array{0: ?string, 1: ?string}|null [ffmpeg, ffprobe]; null: not looked up yet */
    private ?array $binaries = null;

    /**
     * @param ?string $ffmpeg    config "ffmpeg": the binary; null looks it up on PATH and in the usual places
     * @param ?string $ffprobe   config "ffprobe": for the video's length; null looks it up like ffmpeg
     * @param int     $timeLimit seconds (tests shorten it)
     */
    public function __construct(
        private readonly ?string $ffmpeg = null,
        private readonly ?string $ffprobe = null,
        private readonly int $timeLimit = self::TIME_LIMIT,
    ) {}

    public function supports(string $mime): bool
    {
        return in_array($mime, self::MIMES, true) && $this->resolve()[0] !== null;
    }

    public function render(string $file, int $longSide): ?string
    {
        [$ffmpeg, $ffprobe] = $this->resolve();
        if ($ffmpeg === null || $longSide < 1 || !is_file($file)) {
            return null;
        }
        $deadline = microtime(true) + $this->timeLimit;
        $seek = self::seekFor($ffprobe !== null ? self::duration($ffprobe, $file, $deadline) : null);
        foreach (array_unique([$seek, 0.0]) as $at) {
            $out = PdfPreviewRenderer::tempFile();
            if ($this->frame($ffmpeg, $file, $at, $longSide, $out, $deadline)) {
                return $out;
            }
            @unlink($out);
            if (microtime(true) >= $deadline) {
                break;
            }
        }

        return null;
    }

    /** Where the frame is taken (seconds): 10 % into a video shorter than 10 s, else (or without a length) 1 s. */
    public static function seekFor(?float $duration): float
    {
        return $duration !== null && $duration > 0 && $duration < self::SHORT ? $duration * self::SHORT_FRACTION : self::DEFAULT_SEEK;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function resolve(): array
    {
        return $this->binaries ??= [
            PdfPreviewRenderer::findBinary('ffmpeg', $this->ffmpeg),
            PdfPreviewRenderer::findBinary('ffprobe', $this->ffprobe),
        ];
    }

    /** The container's length in seconds as ffprobe reads it; null when it cannot. */
    private static function duration(string $ffprobe, string $file, float $deadline): ?float
    {
        $out = PdfPreviewRenderer::run([
            $ffprobe, '-v', 'error', '-protocol_whitelist', 'file',
            '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $file,
        ], $deadline, capture: true);
        $out = $out !== null ? trim($out) : '';

        return is_numeric($out) && (float) $out > 0 ? (float) $out : null;
    }

    /** The frame at $at seconds into $out (an empty temp file), scaled to $longSide; false when none came out. */
    private function frame(string $ffmpeg, string $file, float $at, int $longSide, string $out, float $deadline): bool
    {
        $ok = PdfPreviewRenderer::run([
            $ffmpeg, '-nostdin', '-v', 'error', '-y',
            '-protocol_whitelist', 'file', '-ss', sprintf('%.3F', $at), '-i', $file,
            '-an', '-sn', '-dn', '-frames:v', '1',
            '-vf', "scale=iw*sar:ih,setsar=1,scale={$longSide}:{$longSide}:force_original_aspect_ratio=decrease",
            '-f', 'image2pipe', '-c:v', 'png', $out,
        ], $deadline) !== null;
        clearstatcache(true, $out);
        $size = $ok && is_file($out) && filesize($out) > 0 ? @getimagesize($out) : false;

        return is_array($size) && $size[2] === IMAGETYPE_PNG && $size[0] > 0 && $size[1] > 0;
    }
}
