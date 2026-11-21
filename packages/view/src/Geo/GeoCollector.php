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
 * Since 0.81 each render frame has its own capture (a frame slot, GeoFrame holding a
 * GeoCapture), not capturing unless GEO handed the render one (captureInto()) or the render
 * turned it on (capture()); what a render records stays in its frame. Outside every render the collector keeps its own.
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
     * Runs $render recording into $capture (GEO hands each page it describes one, and reads the
     * blocks from it afterwards), then puts the render's previous capture back, also when $render
     * throws: outside every render the collector's own, inside one the frame's. What the render
     * recorded before (a scope or hook that used the collector) is taken over by $capture.
     *
     * @template T
     * @param \Closure(): T $render
     * @return T
     *
     * @since 0.81.0
     *
     * @throws \LogicException when $capture recorded something already
     */
    public function captureInto(GeoCapture $capture, \Closure $render): mixed
    {
        $holder = Contexts::depth() === 0 ? null : $this->frameHolder();
        $previous = $holder?->capture ?? $this->own;
        $capture->takeOver($previous);
        if ($holder === null) {
            $this->own = $capture;
        } else {
            $holder->capture = $capture;
        }
        try {
            return $render();
        } finally {
            if ($holder === null) {
                $this->own = $previous;
            } else {
                $holder->capture = $previous;
            }
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

        return $this->frameHolder()->capture;
    }

    private function frameHolder(): GeoFrame
    {
        return Contexts::frame()->slot(GeoFrame::class, static fn (): GeoFrame => new GeoFrame(new GeoCapture(capturing: false)));
    }
}
