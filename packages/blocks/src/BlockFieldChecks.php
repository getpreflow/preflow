<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Data\TypeFieldDefinition;

/**
 * Checks a package adds for the fields of every block, run once when the block registry is
 * built (BlockRegistry::discover(); a registry read from the boot manifest or blocks.cache was
 * checked when that was written). A field type package uses it to refuse a block field's options
 * at boot, as it does for a type's fields.
 *
 * A check gets the block's fields and throws a \LogicException whose message starts with the
 * field (`field "starts": …`); the registry puts the block in front of it. BlocksServiceProvider
 * binds one instance; a provider adds its check in register(), before any registry is built.
 *
 * @since 0.91.0
 */
final class BlockFieldChecks
{
    /** @var list<\Closure(array<string, TypeFieldDefinition>): void> */
    private array $checks = [];

    /** @param \Closure(array<string, TypeFieldDefinition>): void $check */
    public function add(\Closure $check): void
    {
        $this->checks[] = $check;
    }

    /** @return list<\Closure(array<string, TypeFieldDefinition>): void> */
    public function all(): array
    {
        return $this->checks;
    }
}
