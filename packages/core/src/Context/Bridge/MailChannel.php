<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Bridge;

use Preflow\Core\Context\OutboxChannel;

/**
 * The outbox channel of notify's messages ('mail'): sends each QueuedMail that may be sent, in
 * the order queued; one that throws is logged and the others still go out. NotifyOutbox registers
 * it on the frame it queues in; a frame that delivers into its parent hands it on.
 *
 * @since 0.81.0
 */
final class MailChannel implements OutboxChannel
{
    public const NAME = 'mail';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param list<object> $items
     *
     * @throws \LogicException after the others were sent, when an item is not a QueuedMail
     */
    public function deliver(array $items): void
    {
        $foreign = [];
        foreach ($items as $item) {
            if (!$item instanceof QueuedMail) {
                $foreign[] = get_debug_type($item);
                continue;
            }
            if (!$item->sends) {
                continue;
            }
            try {
                ($item->send)();
            } catch (\Throwable $e) {
                // Generic on purpose: a message can carry addresses.
                error_log('A queued mail could not be sent (' . $e::class . ').');
            }
        }
        if ($foreign !== []) {
            throw new \LogicException("The outbox channel 'mail' sends notify's queued mail only; it cannot send " . implode(', ', $foreign) . '.');
        }
    }

    public function describe(object $item): string
    {
        return $item instanceof QueuedMail ? $item->summary : get_debug_type($item);
    }
}
