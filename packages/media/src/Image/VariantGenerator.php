<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

use Preflow\Media\Asset;
use Preflow\Media\Variant;

/**
 * Eager responsive variants, written next to the original as
 * {id}-{width}.{webp|avif}: every configured width below the original plus the
 * original width, in WebP and (when supported) AVIF. Without a backend nothing
 * is generated and images are served as uploaded. A source that needs colour
 * management (CMYK, a profile other than sRGB) is made by Imagick in sRGB, and
 * gets no variants where that is missing; all others use the backend as given.
 */
final class VariantGenerator
{
    /** Formats variants are made for; GIF (animation) and SVG are served as they are. */
    private const array SOURCE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    /** @param list<int> $widths */
    public function __construct(
        private readonly ?ImageBackend $backend,
        private readonly array $widths,
        private readonly int $quality = 75,
        private readonly ColorManagement $color = new ColorManagement(),
    ) {}

    /** @return list<Variant> paths relative to $dir */
    public function generate(string $dir, string $relativeSource, string $mime, int $originalWidth): array
    {
        if (!in_array($mime, self::SOURCE_TYPES, true) || $originalWidth < 1) {
            return [];
        }
        $backend = $this->backendFor(rtrim($dir, '/') . '/' . $relativeSource);
        if ($backend === null) {
            return [];
        }

        // Every configured width the image can fill; its own width only when it is
        // narrower than the largest configured one (a full-size copy is rarely used
        // and the most expensive to encode).
        $configured = array_values(array_filter(array_map('intval', $this->widths), static fn (int $w) => $w > 0));
        $widths = array_filter($configured, static fn (int $w) => $w <= $originalWidth);
        if ($configured === [] || $originalWidth < max($configured)) {
            $widths[] = $originalWidth;
        }
        $widths = array_values(array_unique($widths));
        sort($widths);

        $base = preg_replace('/\.[a-z0-9]+$/', '', $relativeSource);
        $jobs = [];
        foreach (['avif', 'webp'] as $format) {
            if ($backend->supports($format)) {
                foreach ($widths as $width) {
                    $jobs[] = ['dest' => rtrim($dir, '/') . '/' . "{$base}-{$width}.{$format}", 'width' => $width, 'format' => $format, 'path' => "{$base}-{$width}.{$format}"];
                }
            }
        }
        if ($jobs === []) {
            return [];
        }

        $results = $backend->writeVariants(
            rtrim($dir, '/') . '/' . $relativeSource,
            array_map(static fn (array $j) => ['dest' => $j['dest'], 'width' => $j['width'], 'format' => $j['format']], $jobs),
            $this->quality,
        );
        $variants = [];
        foreach ($jobs as $i => $job) {
            if (($results[$i] ?? false) === true) {
                $variants[] = new Variant($job['width'], $job['format'], $job['path']);
            }
        }

        return $variants;
    }

    /** Whether variants can be made for this source type in this format. */
    public function supports(string $mime, string $format): bool
    {
        return $this->backend !== null && in_array($mime, self::SOURCE_TYPES, true)
            && in_array($format, ['avif', 'webp'], true) && $this->backend->supports($format);
    }

    /**
     * One variant, written to a temp file and renamed into place, so a reader
     * never sees half of it. Returns the relative path, or null on failure.
     */
    public function generateOne(string $dir, string $relativeSource, string $mime, int $width, string $format): ?string
    {
        if (!$this->supports($mime, $format) || $width < 1) {
            return null;
        }
        $backend = $this->backendFor(rtrim($dir, '/') . '/' . $relativeSource);
        if ($backend === null || !$backend->supports($format)) {
            return null;
        }
        $rel = preg_replace('/\.[a-z0-9]+$/', '', $relativeSource) . "-{$width}.{$format}";
        $dest = rtrim($dir, '/') . '/' . $rel;
        $tmp = $dest . '.tmp-' . bin2hex(random_bytes(4)) . '.' . $format;
        $ok = ($backend->writeVariants(rtrim($dir, '/') . '/' . $relativeSource, [['dest' => $tmp, 'width' => $width, 'format' => $format]], $this->quality)[0] ?? false) === true;
        if (!$ok || !@rename($tmp, $dest)) {
            @unlink($tmp);

            return null;
        }

        return $rel;
    }

    /** True when the source needs colour management and this site cannot do it (no Imagick with lcms). */
    public function lacksColorManagement(string $source, string $mime): bool
    {
        return in_array($mime, self::SOURCE_TYPES, true) && !$this->color->available() && $this->color->needsConversion($source);
    }

    /** The backend for this source: Imagick converting to sRGB when it needs colour management, else the given one. */
    private function backendFor(string $source): ?ImageBackend
    {
        if ($this->backend === null) {
            return null;
        }
        if (!$this->color->needsConversion($source)) {
            return $this->backend;
        }

        return $this->color->available() ? new ImagickBackend($this->color) : null;
    }

    /** Every variant file of the asset: the recorded ones and those made on demand. */
    public function remove(Asset $asset, string $dir): void
    {
        $files = array_map(static fn (Variant $v) => rtrim($dir, '/') . '/' . $v->path, $asset->variants);
        $base = rtrim($dir, '/') . '/' . preg_replace('/\.[a-z0-9]+$/', '', $asset->path);
        foreach (['avif', 'webp'] as $format) {
            $files = [...$files, ...(glob($base . '-[0-9]*.' . $format) ?: [])];
        }
        foreach (array_unique($files) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
