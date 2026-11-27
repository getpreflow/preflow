<?php

declare(strict_types=1);

namespace Preflow\Blocks;

/**
 * Cleans a block's values before its component gets them (rich text sanitized, nested
 * blocks included). Values are cleaned when they are saved (since 0.93 on every typed write),
 * but content can also arrive by a script writing straight to a driver or older data. The
 * default is CoreBlockTextCleaner (since 0.93; DefaultBlockTextCleaner, which leaves them as
 * they are, before); Folio binds its rich-text sanitizer.
 */
interface BlockTextCleaner
{
    /**
     * @param array<string, mixed> $block a block's values ("_block", "_uid", fields)
     * @return array<string, mixed>
     */
    public function block(array $block): array;
}
