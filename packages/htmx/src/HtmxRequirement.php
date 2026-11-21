<?php

declare(strict_types=1);

namespace Preflow\Htmx;

/**
 * Whether the page one render frame renders needs htmx (HtmxAssets's state, a frame slot).
 *
 * @internal HtmxAssets
 * @since 0.81.0
 */
final class HtmxRequirement
{
    public function __construct(public bool $required = false) {}
}
