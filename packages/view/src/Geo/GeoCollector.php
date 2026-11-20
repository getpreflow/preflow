<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

use Preflow\Core\Context\Contexts;

/**
 * Collects the Markdown that templates declare with `{% apply geo %}` / `@geo`.
 *
 * Records nothing unless capturing, so normal HTML renders are untouched. Each component render
 * opens a block (component()); a block lands in the innermost open component, or in the
 * page-level list when no component is rendering.
 *
 * Since 0.81 each render frame has its own capture (a frame slot, GeoCapture), not capturing
 * unless GEO handed the render one (captureInto()) or the render turned it on (capture()); what
 * a render records stays in its frame. Outside every render the collector keeps its own.
 */
final class GeoCollector
{
    /** The capture outside every render. */
    private GeoCapture $own;

    public function __construct()
    {
        $this->own = new GeoCapture(capturing: false);
    }

    /** Turn recording on or off (for the current render). */
    public function capture(bool $on): void
    {
        $this->state()->capturing = $on;
    }

    public function capturing(): bool
    {
        return $this->state()->capturing;
    }

    /**
     * The current render records into $capture (GEO hands each page it describes one, and reads
     * the blocks from it afterwards). Call it before the render recorded anything.
     *
     * @since 0.81.0
     *
     * @throws \LogicException when the current render frame has a capture already
     */
    public function captureInto(GeoCapture $capture): void
    {
        if (Contexts::depth() === 0) {
            $this->own = $capture;

            return;
        }
        if (Contexts::frame()->slot(GeoCapture::class, static fn (): GeoCapture => $capture) !== $capture) {
            throw new \LogicException('GeoCollector::captureInto() comes too late: the render frame has a GEO capture already.');
        }
    }

    /**
     * Renders one component ($render returns its HTML) in a block of its own while capturing: a
     * component that declared a geo block is replaced by the block's placeholder. The block is
     * closed also when $render throws.
     *
     * @param \Closure(): string $render
     *
     * @since 0.81.0
     */
    public function component(\Closure $render): string
    {
        $capture = $this->state();
        if (!$capture->capturing) {
            return $render();
        }
        $capture->enterComponent();
        try {
            $html = $render();
        } finally {
            $block = $capture->leaveComponent();
        }

        return $block === null ? $html : $capture->placeholder($block);
    }

    /** Record a block (trimmed) in the innermost open component, or at page level. */
    public function add(string $markdown): void
    {
        $this->state()->add($markdown);
    }

    /** Store a block and return the HTML element that stands in for it. */
    public function placeholder(string $markdown): string
    {
        return $this->state()->placeholder($markdown);
    }

    /** The block behind placeholder $n ('' when unknown). */
    public function block(int $n): string
    {
        return $this->state()->block($n);
    }

    /**
     * Blocks added outside any component (page templates), in render order.
     *
     * @return list<string>
     */
    public function pageBlocks(): array
    {
        return $this->state()->pageBlocks();
    }

    /** The current render frame's capture (not capturing until told); the own one outside every render. */
    private function state(): GeoCapture
    {
        if (Contexts::depth() === 0) {
            return $this->own;
        }

        return Contexts::frame()->slot(GeoCapture::class, static fn (): GeoCapture => new GeoCapture(capturing: false));
    }
}
