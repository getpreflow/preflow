<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets;

use Preflow\Folio\Assets\Image\ImageBackend;

/**
 * Eager responsive variants, written next to the original as
 * {id}-{width}.{webp|avif}: every configured width below the original plus the
 * original width, in WebP and (when supported) AVIF. Without a backend nothing
 * is generated and images are served as uploaded.
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
    ) {}

    /** @return list<Variant> paths relative to $dir */
    public function generate(string $dir, string $relativeSource, string $mime, int $originalWidth): array
    {
        if ($this->backend === null || !in_array($mime, self::SOURCE_TYPES, true) || $originalWidth < 1) {
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
            if ($this->backend->supports($format)) {
                foreach ($widths as $width) {
                    $jobs[] = ['dest' => rtrim($dir, '/') . '/' . "{$base}-{$width}.{$format}", 'width' => $width, 'format' => $format, 'path' => "{$base}-{$width}.{$format}"];
                }
            }
        }
        if ($jobs === []) {
            return [];
        }

        $results = $this->backend->writeVariants(
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

    public function remove(Asset $asset, string $dir): void
    {
        foreach ($asset->variants as $variant) {
            $file = rtrim($dir, '/') . '/' . $variant->path;
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
