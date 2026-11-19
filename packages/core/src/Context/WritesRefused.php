<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * A write refused by WritePolicy::Refuse (a fragment request under a preview token): the
 * preview must not pretend a form changed data. The error handler answers it 403 with
 * MESSAGE, which a visitor may read.
 *
 * @since 0.81.0
 */
final class WritesRefused extends \RuntimeException
{
    /** What the visitor reads on the 403 page. */
    public const string MESSAGE = 'This preview does not change data.';

    public function __construct(public readonly SkippedWrite $write)
    {
        parent::__construct(self::MESSAGE);
    }
}
