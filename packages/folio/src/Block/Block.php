<?php

declare(strict_types=1);

namespace Preflow\Folio\Block;

/** Marks a class as a Folio block. "key" is the stable storage identifier. */
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
    ) {}
}
