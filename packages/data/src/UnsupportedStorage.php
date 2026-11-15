<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage was asked for something only a capability gives, and it does not implement it.
 * Still a \LogicException, so code that caught that keeps working.
 *
 * @since 0.78
 */
final class UnsupportedStorage extends \LogicException
{
    /**
     * @param string $storage    the storage name as configured
     * @param string $capability the interface the storage lacks (a class-string)
     * @param string $needs      what needs it, as a short clause
     */
    public function __construct(
        public readonly string $storage,
        public readonly string $capability,
        string $needs,
    ) {
        parent::__construct("Storage [{$storage}] does not implement {$capability}; {$needs}.");
    }
}
