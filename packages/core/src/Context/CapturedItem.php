<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * Something a render queued but did not send: a frame with OutboxPolicy::Capture keeps it for
 * the caller (a preview lists the mail it would send).
 *
 * @since 0.81.0
 */
final readonly class CapturedItem
{
    /**
     * @param string $channel the channel it was queued for
     * @param string $summary what the channel's describe() says about it (its class when no channel is registered)
     * @param object $item the item itself
     */
    public function __construct(
        public string $channel,
        public string $summary,
        public object $item,
    ) {}
}
