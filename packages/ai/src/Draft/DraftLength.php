<?php

declare(strict_types=1);

namespace Preflow\Ai\Draft;

/**
 * How long a draft should be. What that means in words and tokens is the writer's business.
 *
 * @since 0.77
 */
enum DraftLength: string
{
    case Short = 'short';
    case Medium = 'medium';
    case Long = 'long';
}
