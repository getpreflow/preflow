<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * Whether data writes run during a render.
 *
 * @since 0.81.0
 */
enum WritePolicy
{
    /** Writes run. */
    case Allow;
    /** Writes are skipped and reported; the caller sees them as written. */
    case Skip;
    /** A write fails (a fragment under a preview token must not pretend it changed data). */
    case Refuse;
}
