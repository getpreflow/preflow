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

    private FrameOutbox $outbox;

    /** @var array<string, object> */
    private array $slots = [];

    /** @var list<SkippedWrite> */
    private array $skippedWrites = [];

    /** @internal Contexts::run() opens frames; $parent's channels are known to this one */
    public function __construct(RenderContext $context, ?RenderFrame $parent = null)
    {
        $this->context = $context;
        $this->outbox = new FrameOutbox($parent?->outbox->channels() ?? []);
    }

    public function outbox(): FrameOutbox
    {
        return $this->outbox;
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

    /** @return list<SkippedWrite> the writes this render skipped, its nested renders' included once they closed */
    public function skippedWrites(): array
    {
        return $this->skippedWrites;
    }
}
