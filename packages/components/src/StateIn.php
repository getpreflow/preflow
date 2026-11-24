<?php

declare(strict_types=1);

namespace Preflow\Components;

/**
 * Names the StateStore class a component keeps its state in. Without it: SignedStateStore.
 *
 * @since 0.86.0
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class StateIn
{
    /** @param class-string<StateStore> $store */
    public function __construct(public readonly string $store) {}
}
