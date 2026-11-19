<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * One frame's outbox: what the render queued and the work it scheduled for after the response.
 * When the frame closes, Contexts applies the context's OutboxPolicy:
 * - Deliver: queued items and after-response work move to the parent frame; the outermost frame
 *   delivers what is still queued through the channels and runs what is still scheduled;
 * - Discard: both are dropped;
 * - Capture: the items stay here for the caller to drain() into a RenderResult; the
 *   after-response work is dropped.
 * A render that throws leaves nothing behind: its frame's outbox is dropped whatever the policy.
 *
 * Channels are registered on a frame (FrameOutbox::channel(), typically from a Contexts::onEnter()
 * hook) and are known to every frame opened inside it.
 *
 * @since 0.81.0
 */
final class FrameOutbox implements Outbox
{
    /** @var list<array{string, object}> channel name and item, in queue order */
    private array $items = [];

    /** @var list<\Closure(): void> */
    private array $work = [];

    /** @param array<string, OutboxChannel> $channels the channels known to the parent frame */
    public function __construct(private array $channels = []) {}

    public function queue(object $item, string $channel): void
    {
        $this->items[] = [$channel, $item];
    }

    public function afterResponse(\Closure $work): void
    {
        $this->work[] = $work;
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
     * channels were first used (the outermost frame's owner calls it after emitting the response).
     *
     * @throws \LogicException when an item's channel is not registered (nothing is sent then)
     */
    public function deliver(): void
    {
        $batches = [];
        foreach ($this->items as [$channel, $item]) {
            if (!isset($this->channels[$channel])) {
                throw new \LogicException("No outbox channel '{$channel}' is registered, so a queued " . $item::class . ' cannot be sent.');
            }
            $batches[$channel][] = $item;
        }
        $this->items = [];
        foreach ($batches as $channel => $items) {
            $this->channels[$channel]->deliver($items);
        }
    }

    /**
     * Runs the scheduled work now, also work added while it runs. A failure is logged (its
     * message only) and the next piece of work still runs.
     */
    public function runAfterResponse(): void
    {
        while ($this->work !== []) {
            $work = array_shift($this->work);
            try {
                $work();
            } catch (\Throwable $e) {
                error_log('After-response work failed: ' . $e->getMessage());
            }
        }
    }

    /** Drops every queued item and all scheduled work. */
    public function discard(): void
    {
        $this->items = [];
        $this->work = [];
    }

    /**
     * Moves the queued items and the scheduled work to $parent (OutboxPolicy::Deliver when a
     * frame closes inside another).
     *
     * @internal Contexts
     */
    public function moveTo(FrameOutbox $parent): void
    {
        foreach ($this->items as [$channel, $item]) {
            $parent->queue($item, $channel);
        }
        foreach ($this->work as $work) {
            $parent->afterResponse($work);
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
}
