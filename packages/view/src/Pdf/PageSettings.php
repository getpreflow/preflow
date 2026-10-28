<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

/**
 * Page size, orientation and margins (all lengths in mm), and the templates
 * printed at the top and bottom of every page. A width and height together
 * set a custom page size and take precedence over format and orientation.
 */
final readonly class PageSettings
{
    public function __construct(
        public string $format = 'A4',
        public string $orientation = 'P',
        public float $marginTop = 15,
        public float $marginRight = 15,
        public float $marginBottom = 15,
        public float $marginLeft = 15,
        public ?string $headerTemplate = null,
        public ?string $footerTemplate = null,
        public ?float $width = null,
        public ?float $height = null,
    ) {
        if (!in_array($orientation, ['P', 'L'], true)) {
            throw new \InvalidArgumentException("Page orientation must be 'P' (portrait) or 'L' (landscape); got '{$orientation}'.");
        }
        if (($width === null) !== ($height === null)) {
            throw new \InvalidArgumentException('A custom page size needs both width and height.');
        }
        if ($width !== null && ($width <= 0 || $height <= 0)) {
            throw new \InvalidArgumentException('A custom page size must be larger than 0 mm.');
        }
    }
}
