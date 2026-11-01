<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

/**
 * Collects the Markdown that templates declare with `{% apply geo %}` / `@geo`.
 *
 * Request-scoped. Records nothing unless capturing, so normal HTML renders are
 * untouched. Each component render opens a frame; a block lands in the innermost
 * open frame, or in the page-level list when no component is rendering.
 */
final class GeoCollector
{
    private bool $capturing = false;

    /** @var list<?string> open frames, innermost last; null = no block yet */
    private array $frames = [];

    /** @var list<string> */
    private array $pageBlocks = [];

    /** @var list<string> blocks stored behind placeholders */
    private array $blocks = [];

    /** Turn recording on or off. */
    public function capture(bool $on): void
    {
        $this->capturing = $on;
    }

    public function capturing(): bool
    {
        return $this->capturing;
    }

    /** Open a frame around one component render. */
    public function enter(): void
    {
        $this->frames[] = null;
    }

    /**
     * Close the innermost frame.
     *
     * @return ?string the frame's block ('' for an empty block), null when it has none
     */
    public function leave(): ?string
    {
        return array_pop($this->frames);
    }

    /** Record a block (trimmed) in the innermost open frame, or at page level. */
    public function add(string $markdown): void
    {
        if (!$this->capturing) {
            return;
        }

        $markdown = trim($markdown);

        if ($this->frames === []) {
            $this->pageBlocks[] = $markdown;
            return;
        }

        $last = array_key_last($this->frames);
        $current = $this->frames[$last];
        $this->frames[$last] = match (true) {
            $current === null || $current === '' => $markdown,
            $markdown === '' => $current,
            default => $current . "\n\n" . $markdown,
        };
    }

    /** Store a block and return the HTML element that stands in for it. */
    public function placeholder(string $markdown): string
    {
        $this->blocks[] = $markdown;
        $n = array_key_last($this->blocks);

        return '<preflow-geo data-geo="' . $n . '"></preflow-geo>';
    }

    /** The block behind placeholder $n ('' when unknown). */
    public function block(int $n): string
    {
        return $this->blocks[$n] ?? '';
    }

    /**
     * Blocks added outside any component (page templates), in render order.
     *
     * @return list<string>
     */
    public function pageBlocks(): array
    {
        return $this->pageBlocks;
    }

    /** Forget all frames and blocks; the capture flag is kept. */
    public function reset(): void
    {
        $this->frames = [];
        $this->pageBlocks = [];
        $this->blocks = [];
    }
}
