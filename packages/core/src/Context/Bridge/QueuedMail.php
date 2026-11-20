<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Bridge;

/**
 * One message notify queued in a render's outbox (channel 'mail'): the closure that sends it, the
 * line a preview lists for it, and whether it may be sent at all. A message queued (or sent with
 * send()) where the render captures keeps $sends false: it is listed, and never sent, also when
 * the frame it ends up in delivers afterwards (a capturing within() stretch inside a request).
 *
 * @since 0.81.0
 */
final readonly class QueuedMail
{
    /** @param \Closure(): void $send sends the message; never throws (notify reports its own failures) */
    public function __construct(
        public \Closure $send,
        public string $summary,
        public bool $sends,
    ) {}
}
