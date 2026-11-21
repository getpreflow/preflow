<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/**
 * A render asked LiveContent for a read mode publishing cannot answer yet: the site as of a date
 * (RenderContext::asOf()) needs reads of the revisions, which publishing does not offer. Thrown on
 * the first read, so such a render fails clearly instead of showing today's content.
 *
 * @since 0.81.0
 */
final class UnsupportedReadMode extends \LogicException
{
    public static function asOf(): self
    {
        return new self('Reading the site as of a date is not supported yet.');
    }
}
