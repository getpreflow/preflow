<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * Code asked for the render context outside every render: a bug in the caller (or code that
 * runs before boot or in a shutdown function, which uses Contexts::peek()). The message names
 * the caller's file and line.
 *
 * @since 0.81.0
 */
final class NoRenderContext extends \LogicException
{
    public static function at(string $method, ?string $file, ?int $line): self
    {
        $where = $file === null ? 'an unknown place' : $file . ':' . ($line ?? 0);

        return new self(
            "There is no render context: Contexts::{$method}() was called outside a render, at {$where}. "
            . 'Open one with Contexts::run(), or use Contexts::peek() where a default without a render is documented.',
        );
    }
}
