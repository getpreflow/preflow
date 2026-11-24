<?php

declare(strict_types=1);

namespace Preflow\Htmx;

/**
 * The other drivers' option keys HtmxDriver has warned about, per render frame (one warning per
 * key and request).
 *
 * @internal
 * @since 0.86.0
 */
final class WarnedOptions
{
    /** @var array<string, true> */
    public array $keys = [];
}
