<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Data\TypeFamily;

/** Marks a class as a block. "key" is the stable storage identifier. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Block
{
    /** The group of blocks that name none (the editor shows it in the interface language). */
    public const string DEFAULT_GROUP = 'Blocks';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $group = self::DEFAULT_GROUP,
        public readonly ?string $icon = null,
        public readonly ?string $description = null,
        /**
         * What kind of content the block holds, as an admin tints its icon (TypeFamily); null: none.
         *
         * @since 0.75
         */
        public readonly ?TypeFamily $family = null,
    ) {}
}
