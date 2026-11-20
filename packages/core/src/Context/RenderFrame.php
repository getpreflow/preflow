<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * What one render produces, kept apart from every other render: its outbox (mail, cookies,
 * after-response work), its collectors (assets, page meta, cache tags, … as slots) and the data
 * writes it skipped. Contexts::run() opens one per render; Contexts::within() and narrow() change
 * its context but keep the frame.
 *
 * @since 0.81.0
 */
final class RenderFrame
{
    /** The context the frame renders under now. Only Contexts::narrow() and within() replace it. */
    public RenderContext $context;

    /**
     * The outbox policy run() opened the frame with: narrow() and within() never make the frame
     * deliver when this does not.
     *
     * @since 0.81.0
     */
    public readonly OutboxPolicy $openedOutbox;

    /**
     * The write policy run() opened the frame with: narrow() and within() never make the frame
     * write when this does not.
     *
     * @since 0.81.0
     */
    public readonly WritePolicy $openedWrites;

    private FrameOutbox $outbox;

    private readonly ?RenderFrame $parent;

    /** @var array<string, object> */
    private array $slots = [];

    /** @var list<SkippedWrite> */
    private array $skippedWrites = [];

    private ?\Throwable $failure = null;

    private int $departures = 0;

    /** @internal Contexts::run() opens frames; $parent's channels are known to this one */
    public function __construct(RenderContext $context, ?RenderFrame $parent = null)
    {
        $this->context = $context;
        $this->openedOutbox = $context->outbox;
        $this->openedWrites = $context->writes;
        $this->parent = $parent;
        $outermost = $parent === null;
        $this->outbox = new FrameOutbox(
            $parent?->outbox->channels() ?? [],
            fn (): bool => $outermost
                && Contexts::depth() === 1
                && Contexts::frame() === $this
                && $this->context->outbox === OutboxPolicy::Deliver,
            $this->outboxPolicy(...),
        );
    }

    public function outbox(): FrameOutbox
    {
        return $this->outbox;
    }

    /**
     * What happens now to an item queued in this frame, wherever it ends up: this frame's own
     * policy when it discards or captures; while it delivers, it hands its items to the frame
     * around it, so that frame's policy (now) decides, and so on outward; the outermost frame
     * sends. The frame's outbox applies it to everything queued (FrameOutbox::queue(),
     * afterResponse()), so a render inside a discarding or capturing stretch of the frame around
     * it (Contexts::within()) queues nothing that would be sent once the stretch is over.
     *
     * @since 0.81.0
     */
    public function outboxPolicy(): OutboxPolicy
    {
        $policy = $this->context->outbox;

        return $policy === OutboxPolicy::Deliver && $this->parent !== null ? $this->parent->outboxPolicy() : $policy;
    }

    /**
     * This frame's collector of class $key, made by $make on first use (asset registries, page
     * meta, the cookie queue…): one object per frame, shared by within().
     *
     * @template T of object
     * @param class-string<T> $key
     * @param \Closure(): T $make
     * @return T
     *
     * @throws \LogicException when $make returns something that is not a $key
     */
    public function slot(string $key, \Closure $make): object
    {
        if (isset($this->slots[$key])) {
            /** @var T */
            return $this->slots[$key];
        }
        $made = $make();
        if (!$made instanceof $key) {
            throw new \LogicException("The frame slot {$key} was made as " . get_debug_type($made) . '.');
        }

        return $this->slots[$key] = $made;
    }

    public function recordSkippedWrite(SkippedWrite $w): void
    {
        $this->skippedWrites[] = $w;
    }

    /**
     * The error the render failed with although it answered (the kernel turned it into its error
     * page): kept for the one who asked for the render (GEO reports it), the first one wins.
     *
     * @since 0.81.0
     */
    public function recordFailure(\Throwable $e): void
    {
        $this->failure ??= $e;
    }

    /** @since 0.81.0 */
    public function failure(): ?\Throwable
    {
        return $this->failure;
    }

    /**
     * Notes that the frame entered a context that is not the public default (Contexts::narrow()
     * or within()).
     *
     * @internal Contexts
     * @since 0.81.0
     */
    public function noteDeparture(): void
    {
        $this->departures++;
    }

    /**
     * How often the frame entered a context that is not the public default through narrow() or
     * within(), even for a stretch it left again: the page cache compares it before and after a
     * render it may store, so draft content read inside a within() is never stored.
     *
     * @since 0.81.0
     */
    public function departures(): int
    {
        return $this->departures;
    }

    /** @return list<SkippedWrite> the writes this render skipped, its nested renders' included once they closed */
    public function skippedWrites(): array
    {
        return $this->skippedWrites;
    }
}
