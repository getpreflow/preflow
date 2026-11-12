<?php

declare(strict_types=1);

namespace Preflow\Blocks;

/**
 * The attributes a block's root element gets in preview mode, so an editor can find the
 * block on the page (no extra wrapper: the page's layout and selectors behave as on the
 * public site). DefaultBlockPreviewMarks adds data-block and data-block-label; Folio's
 * editor binds its own.
 */
interface BlockPreviewMarks
{
    /**
     * @param array<string, mixed> $block the block's values ("_uid" is its id within the page)
     * @return array<string, string> attribute name (data-…) => value, escaped by the component renderer
     */
    public function attributes(BlockDefinition $definition, array $block): array;
}
