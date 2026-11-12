<?php

declare(strict_types=1);

namespace Preflow\Blocks;

/**
 * Cleans a block's values before its component gets them (rich text sanitized, nested
 * blocks included). Values are usually cleaned when they are saved, but content can also
 * arrive by import, app code or older data. The default leaves them as they are; Folio
 * binds its rich-text sanitizer.
 */
interface BlockTextCleaner
{
    /**
     * @param array<string, mixed> $block a block's values ("_block", "_uid", fields)
     * @return array<string, mixed>
     */
    public function block(array $block): array;
}
