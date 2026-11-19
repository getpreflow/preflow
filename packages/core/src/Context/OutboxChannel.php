<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * How queued items of one kind (mail, cookies) are sent. A package registers its channel on a
 * frame's outbox (FrameOutbox::channel()); frames opened inside that frame know it too.
 *
 * @since 0.81.0
 */
interface OutboxChannel
{
    /** The channel name items are queued under. */
    public function name(): string;

    /**
     * Send $items, in the order they were queued.
     *
     * @param list<object> $items
     */
    public function deliver(array $items): void;

    /** One line saying what $item would do ("Mail to …"), for a preview's list of what it would send. */
    public function describe(object $item): string;
}
