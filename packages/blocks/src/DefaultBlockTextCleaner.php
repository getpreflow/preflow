<?php

declare(strict_types=1);

namespace Preflow\Blocks;

/**
 * A BlockTextCleaner that changes nothing: values reach the block as they are stored. The default
 * before 0.93; since then blocks bind CoreBlockTextCleaner, and a site that wants its values
 * uncleaned binds this one.
 */
final class DefaultBlockTextCleaner implements BlockTextCleaner
{
    public function block(array $block): array
    {
        return $block;
    }
}
