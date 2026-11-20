<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

/**
 * The GEO capture a render frame records into (GeoCollector's frame slot); captureInto() swaps
 * it for the one GEO hands the render and puts it back afterwards.
 *
 * @internal GeoCollector
 * @since 0.81.0
 */
final class GeoFrame
{
    public function __construct(public GeoCapture $capture) {}
}
