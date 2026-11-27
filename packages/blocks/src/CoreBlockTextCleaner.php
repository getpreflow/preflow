<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Data\Values\StoredCleaning;

/**
 * The BlockTextCleaner blocks bind when no provider bound one (0.93): a block's values cleaned by
 * the core, as every typed write cleans them (StoredCleaning::fields() over the block's declared
 * fields: rich text through the core HtmlCleaner, a site's own when it binds one; nested blocks
 * and the columns of an areas field through BlocksFieldValue, to any depth). Values are cleaned
 * when they are saved; this cleans again on the way to the template, for data an import, app code
 * or a script wrote straight to a driver. A block no class declares comes back as given.
 *
 * @since 0.93.0
 */
final class CoreBlockTextCleaner implements BlockTextCleaner
{
    public function __construct(
        private readonly StoredCleaning $cleaning,
        private readonly BlockRegistry $blocks,
    ) {}

    public function block(array $block): array
    {
        $definition = $this->blocks->get(is_string($block['_block'] ?? null) ? $block['_block'] : '');

        return $definition === null ? $block : $this->cleaning->fields($block, $definition->fields);
    }
}
