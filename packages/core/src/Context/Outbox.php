<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * The seam through which anything a render sends or schedules joins the current frame: mail and
 * similar deliveries are queued by channel, work for after the response is added as a closure.
 * The frame that owns the outbox applies its context's OutboxPolicy when it closes.
 *
 * @since 0.81.0
 */
interface Outbox
{
    /** Queue $item for the channel named $channel (for example 'mail' or 'cookies'). */
    public function queue(object $item, string $channel): void;

    /** Run $work after the response has gone out (a cache rebuild, a slow notification). */
    public function afterResponse(\Closure $work): void;
}
