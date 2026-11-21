<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Bridge;

use Preflow\Core\Context\OutboxChannel;
use Preflow\Notify\Deferred\Deferred;

/**
 * The outbox channel of notify's messages ('notify'): sends each Deferred notify queued, in the
 * order queued, with the Notifier's deliver() (which reports a failure to the message's
 * onFailure or the log and never throws). A RecordedSend is never sent. NotifyOutbox registers it
 * on the frame it queues in; a frame that delivers into its parent hands it on.
 *
 * @since 0.81.0
 */
final class NotifyChannel implements OutboxChannel
{
    public const NAME = 'notify';

    /** @param \Closure(Deferred): void $send sends one message (the Notifier's deliver()) */
    public function __construct(private readonly \Closure $send) {}

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param list<object> $items
     *
     * @throws \LogicException after the others were sent, when an item is not notify's
     */
    public function deliver(array $items): void
    {
        $foreign = [];
        foreach ($items as $item) {
            if ($item instanceof RecordedSend) {
                continue;
            }
            if (!$item instanceof Deferred) {
                $foreign[] = get_debug_type($item);
                continue;
            }
            try {
                ($this->send)($item);
            } catch (\Throwable $e) {
                // Generic on purpose: a message can carry addresses.
                error_log('A queued notification could not be sent (' . $e::class . ').');
            }
        }
        if ($foreign !== []) {
            throw new \LogicException("The outbox channel 'notify' sends notify's queued messages only; it cannot send " . implode(', ', $foreign) . '.');
        }
    }

    public function describe(object $item): string
    {
        return $item instanceof Deferred || $item instanceof RecordedSend ? $item->summary : get_debug_type($item);
    }
}
