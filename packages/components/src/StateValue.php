<?php

declare(strict_types=1);

namespace Preflow\Components;

/**
 * An object a component can keep as state: it turns itself into plain values (scalars, null,
 * arrays of those) and is rebuilt from them.
 *
 * @since 0.86.0
 */
interface StateValue
{
    /** @return array<string, mixed> */
    public function toState(): array;

    /**
     * @param array<string, mixed> $state what toState() returned, possibly of an older shape
     */
    public static function fromState(array $state): static;
}
