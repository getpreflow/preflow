<?php

declare(strict_types=1);

namespace Preflow\Notify\Deferred;

use Preflow\Notify\Message;

/**
 * One message queued with Notifier::sendLater(): the message itself, the notify channel it goes
 * through ('email', 'sms', …), what to call when it fails, and one line saying what it sends (for
 * a list of what a render would have sent). A queue keeps it until it sends it with
 * Notifier::deliver(); a durable queue (a cron worker sending later) stores the message, which
 * is a plain value. $onFailure is a closure and lives only as long as the process.
 *
 * @since 0.81.0
 */
final readonly class Deferred
{
    /** @param (\Closure(\Throwable): void)|null $onFailure */
    public function __construct(
        public Message $message,
        public string $channel,
        public ?\Closure $onFailure,
        public string $summary,
    ) {}
}
