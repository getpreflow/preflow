<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

enum OriginVerdict
{
    /** The request demonstrably comes from the site itself or a trusted origin. */
    case Pass;

    /** The request demonstrably comes from somewhere else. */
    case Refuse;

    /** No usable signal; the token alone decides. */
    case Unknown;
}
