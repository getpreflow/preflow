<?php

declare(strict_types=1);

namespace Preflow\Blocks;

/** The BlockTextCleaner without an app of its own: values reach the block as they are stored. */
final class DefaultBlockTextCleaner implements BlockTextCleaner
{
    public function block(array $block): array
    {
        return $block;
    }
}
