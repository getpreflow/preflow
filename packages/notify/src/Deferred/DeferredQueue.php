<?php

declare(strict_types=1);

namespace Preflow\Notify\Deferred;

/**
 * Where the Notifier keeps what sendLater() queued until it goes out, and what decides whether a
 * message may go out at all. notify's own InMemoryQueue keeps 0.80's behaviour (a list flushed by
 * flushDeferred(), dropped while discarding()); an app passes its own, for example one that joins
 * the current render's outbox, so that a render that must not send (a preview, an audit) neither
 * queues nor sends.
 *
 * @since 0.81.0
 */
interface DeferredQueue
{
    /** Messages go out as usual. */
    public const DELIVER = 'deliver';

    /** Messages are dropped. */
    public const DISCARD = 'discard';

    /** Messages are kept for whoever asked for the work to list (a preview), never sent. */
    public const CAPTURE = 'capture';

    /**
     * Queues $send, which sends one message when the queue lets it (a failure is its own to
     * handle: it never throws). $summary says in one line what it sends ("Email to …"), for a
     * list of what a render would have sent.
     *
     * @param \Closure(): void $send
     */
    public function push(\Closure $send, string $summary): void;

    /** Sends what is queued and may go out now. */
    public function flush(): void;

    /**
     * Runs $fn and returns what it returns; what is pushed or sent meanwhile is dropped. Calls
     * nest; the previous policy is back afterwards, also when $fn throws.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function discarding(\Closure $fn): mixed;

    /** What happens to a message now: DELIVER, DISCARD or CAPTURE. */
    public function policy(): string;

    /**
     * Notes a message that send() did not deliver because policy() is not DELIVER, so a capture
     * lists it with what was queued; under DISCARD it is dropped.
     */
    public function record(string $summary): void;
}
