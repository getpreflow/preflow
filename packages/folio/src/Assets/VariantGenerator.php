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

        $widths = array_values(array_unique(array_filter(
            [...array_map('intval', $this->widths), $originalWidth],
            static fn (int $w) => $w > 0 && $w <= $originalWidth,
        )));
        sort($widths);

        $base = preg_replace('/\.[a-z0-9]+$/', '', $relativeSource);
        $variants = [];
        foreach (['avif', 'webp'] as $format) {
            if (!$this->backend->supports($format)) {
                continue;
            }
            foreach ($widths as $width) {
                $path = "{$base}-{$width}.{$format}";
                if ($this->backend->resize(rtrim($dir, '/') . '/' . $relativeSource, rtrim($dir, '/') . '/' . $path, $width, $format, $this->quality)) {
                    $variants[] = new Variant($width, $format, $path);
                }
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
