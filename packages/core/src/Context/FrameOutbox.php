<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * One frame's outbox: what the render queued and the work it scheduled for after the response.
 * When the frame closes, Contexts applies the context's OutboxPolicy:
 * - Deliver: queued items and after-response work move to the parent frame; the outermost frame
 *   sends what is still queued through the channels and runs what is still scheduled, round
 *   after round while that work queues more (at most 10 rounds; what is left then is logged);
 * - Discard: both are dropped;
 * - Capture: the items stay here for the caller to drain() into a RenderResult; the
 *   after-response work is dropped.
 * A render that throws leaves nothing behind: its frame's outbox is dropped whatever the policy.
 *
 * The policy also applies when something is queued (since 0.81.0): queue() and afterResponse()
 * follow the frame's policy at that moment (RenderFrame::outboxPolicy(), which a within()
 * stretch changes and a frame that delivers takes from the frame around it). Under Discard both
 * are dropped at once; under Capture an item is kept as captured only (drain() returns it, it is
 * never sent, also when the frame delivers once the stretch is over) and the work is dropped. So
 * nothing queued in a discarding or capturing stretch goes out after it, on any channel.
 *
 * Only the outermost open frame under Deliver sends: deliver() and runAfterResponse() throw a
 * LogicException anywhere else (inside a preview, an audit, a nested render, or on a closed
 * frame), so a captured mail is never sent. The frame's owner uses Contexts::deliverNow().
 *
 * Channels are registered on a frame (FrameOutbox::channel(), typically from a Contexts::onEnter()
 * hook) and are known to every frame opened inside it.
 *
 * @since 0.81.0
 */
final class FrameOutbox implements Outbox
{
    /** @var list<array{string, object, bool}> channel name, item and whether it is captured only, in queue order */
    private array $items = [];

    /** @var list<\Closure(): void> */
    private array $work = [];

    /**
     * @param array<string, OutboxChannel> $channels the channels known to the parent frame
     * @param (\Closure(): bool)|null $sends whether this outbox may send now (RenderFrame passes it; null never sends)
     * @param (\Closure(): OutboxPolicy)|null $policy since 0.81.0: the frame's policy now (RenderFrame passes
     *        outboxPolicy(); null delivers)
     *
     * @internal RenderFrame
     */
    public function __construct(private array $channels = [], private ?\Closure $sends = null, private ?\Closure $policy = null) {}

    public function queue(object $item, string $channel): void
    {
        $policy = $this->policy();
        if ($policy === OutboxPolicy::Discard) {
            return;
        }
        $this->items[] = [$channel, $item, $policy === OutboxPolicy::Capture];
    }

    public function afterResponse(\Closure $work): void
    {
        if ($this->policy() !== OutboxPolicy::Deliver) {
            return;
        }
        $this->work[] = $work;
    }

    /**
     * The channel registered under $name for this frame, or null.
     *
     * @since 0.81.0
     */
    public function channelNamed(string $name): ?OutboxChannel
    {
        return $this->channels[$name] ?? null;
    }

    /**
     * Takes the items queued for $channel out and returns those that may be sent, in queue order
     * (an item queued while the frame captured is dropped: it was never to be sent). The other
     * channels' items stay. For the owner of a channel that sends its own items now (notify's
     * flushDeferred() in a command).
     *
     * @since 0.81.0
     *
     * @return list<object>
     */
    public function take(string $channel): array
    {
        $taken = [];
        $kept = [];
        foreach ($this->items as $entry) {
            if ($entry[0] !== $channel) {
                $kept[] = $entry;
            } elseif (!$entry[2]) {
                $taken[] = $entry[1];
            }
        }
        $this->items = $kept;

        return $taken;
    }

    /**
     * Moves the items queued for $channel to $to, as they are (captured only stays captured
     * only), and registers the channel there when $to does not know one of that name. For a
     * request of its own that failed inside another frame: its mail still goes with that frame.
     *
     * @since 0.81.0
     */
    public function handOn(string $channel, FrameOutbox $to): void
    {
        $kept = [];
        foreach ($this->items as $entry) {
            if ($entry[0] === $channel) {
                $to->items[] = $entry;
            } else {
                $kept[] = $entry;
            }
        }
        $this->items = $kept;
        if (isset($this->channels[$channel])) {
            $to->channels[$channel] ??= $this->channels[$channel];
        }
    }

    private function policy(): OutboxPolicy
    {
        return $this->policy === null ? OutboxPolicy::Deliver : ($this->policy)();
    }

    /** Registers $c for this frame and the frames opened inside it (one per name; a later one replaces it). */
    public function channel(OutboxChannel $c): void
    {
        $this->channels[$c->name()] = $c;
    }

    /**
     * Takes every queued item out, each with its channel's description (its class when no
     * channel of that name is registered).
     *
     * @return list<CapturedItem>
     */
    public function drain(): array
    {
        $items = $this->items;
        $this->items = [];

        return array_map(function (array $entry): CapturedItem {
            [$channel, $item] = $entry;
            $known = $this->channels[$channel] ?? null;

            return new CapturedItem($channel, $known === null ? $item::class : $known->describe($item), $item);
        }, $items);
    }

    /**
     * Sends every queued item now through its channel, one deliver() per channel in the order the
     * channels were first used. The queue is empty afterwards: a channel that fails, or items
     * whose channel is not registered, do not keep the other channels from sending; the first
     * such failure is thrown once all were tried.
     *
     * @internal Contexts (the owner calls Contexts::deliverNow())
     *
     * @throws \LogicException outside the outermost open frame under Deliver (nothing is sent
     *                         then), or when an item's channel is not registered
     * @throws \Throwable what a failing channel threw
     */
    public function deliver(): void
    {
        $this->mustSend('deliver');
        $batches = [];
        $failure = null;
        foreach ($this->items as [$channel, $item, $capturedOnly]) {
            if ($capturedOnly) {
                continue; // queued while the frame captured: listed, never sent
            }
            if (!isset($this->channels[$channel])) {
                $failure ??= new \LogicException("No outbox channel '{$channel}' is registered, so a queued " . $item::class . ' cannot be sent.');
                continue;
            }
            $batches[$channel][] = $item;
        }
        $this->items = [];
        foreach ($batches as $channel => $items) {
            try {
                $this->channels[$channel]->deliver($items);
            } catch (\Throwable $e) {
                if ($failure === null) {
                    $failure = $e;
                } else {
                    error_log("The outbox channel '{$channel}' failed: " . $e->getMessage());
                }
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Runs the work scheduled so far; work it schedules waits for the next round. A failure is
     * logged (its message only) and the next piece of work still runs.
     *
     * @internal Contexts (the owner calls Contexts::deliverNow())
     *
     * @throws \LogicException outside the outermost open frame under Deliver
     */
    public function runAfterResponse(): void
    {
        $this->mustSend('runAfterResponse');
        $round = $this->work;
        $this->work = [];
        foreach ($round as $work) {
            try {
                $work();
            } catch (\Throwable $e) {
                error_log('After-response work failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Drops the work scheduled for after the response; the queued items stay.
     *
     * @internal Contexts (Contexts::deliverQueued())
     */
    public function dropAfterResponse(): void
    {
        $this->work = [];
    }

    /** Drops every queued item and all scheduled work. */
    public function discard(): void
    {
        $this->items = [];
        $this->work = [];
    }

    /**
     * Moves the queued items and the scheduled work to $parent (OutboxPolicy::Deliver when a
     * frame closes inside another), with the channels registered here that $parent does not
     * know (since 0.81.0), so a channel registered inside a nested render sends its items there.
     *
     * @internal Contexts
     */
    public function moveTo(FrameOutbox $parent): void
    {
        $parent->channels += $this->channels;
        // As they are: the policy applied when each was queued (the frame's own, then the one
        // around it, which cannot change while this frame is open).
        foreach ($this->items as $entry) {
            $parent->items[] = $entry;
        }
        foreach ($this->work as $work) {
            $parent->work[] = $work;
        }
        $this->discard();
    }

    /**
     * Drops the scheduled work and keeps the queued items for drain() (OutboxPolicy::Capture).
     *
     * @internal Contexts
     */
    public function keepForCapture(): void
    {
        $this->work = [];
    }

    /**
     * How many items are queued and how many pieces of work are scheduled.
     *
     * @internal Contexts
     *
     * @return array{int, int}
     */
    public function pending(): array
    {
        return [count($this->items), count($this->work)];
    }

    /**
     * The channels this outbox knows, for a frame opened inside its frame.
     *
     * @internal RenderFrame
     *
     * @return array<string, OutboxChannel>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    private function mustSend(string $method): void
    {
        if ($this->sends === null || !($this->sends)()) {
            throw new \LogicException(
                "FrameOutbox::{$method}() sends only from the outermost open frame under OutboxPolicy::Deliver; "
                . 'a nested, capturing, discarding or closed frame never sends (its owner uses Contexts::deliverNow()).',
            );
        }
    }
}
