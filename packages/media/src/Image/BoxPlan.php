<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

use Preflow\Media\Asset;

/**
 * How one box preset is cut from an asset's delivery image now (MediaLibrary::boxPlan()).
 * The files are {base}-{w}.{avif|webp} for each of $widths; a change to the image, its
 * edits, its format crop or its focal point gives a new $base.
 */
final readonly class BoxPlan
{
    /**
     * @param Asset $source the delivery image (the master when edited, a document's preview)
     * @param string $base {delivery stem}-{preset}-{key}
     * @param int $width the 1x display width: the box (cover), or the picture fitted into it (contain)
     * @param int $height the 1x display height
     * @param list<int> $widths the file widths, never wider than $crop
     * @param array{x: int, y: int, w: int, h: int} $crop what is taken, in pixels of $source
     */
    public function __construct(
        public Format $format,
        public Asset $source,
        public string $base,
        public int $width,
        public int $height,
        public array $widths,
        public array $crop,
    ) {}
}
