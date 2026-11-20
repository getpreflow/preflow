<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

/**
 * The Markdown blocks one render declared with `{% apply geo %}` / `@geo`: GeoCollector's state,
 * one per render frame (a frame slot). GEO makes one for each page it describes and hands it to
 * that page's render (GeoCollector::captureInto()), then reads the blocks from it.
 *
 * @since 0.81.0
 */
final class GeoCapture
{
    /** @var list<?string> open component blocks, innermost last; null = no block yet */
    private array $components = [];

    /** @var list<string> */
    private array $pageBlocks = [];

    /** @var list<string> blocks stored behind placeholders */
    private array $blocks = [];

    /** @param bool $capturing whether blocks are recorded (a capture made by GEO records) */
    public function __construct(public bool $capturing = true) {}

    /** Opens a block around one component render. */
    public function enterComponent(): void
    {
        $this->components[] = null;
    }

    /**
     * Closes the innermost component block.
     *
     * @return ?string the component's block ('' for an empty block), null when it has none
     */
    public function leaveComponent(): ?string
    {
        return array_pop($this->components);
    }

    /** Records a block (trimmed) in the innermost open component, or at page level. */
    public function add(string $markdown): void
    {
        if (!$this->capturing) {
            return;
        }
        $markdown = trim($markdown);
        if ($this->components === []) {
            $this->pageBlocks[] = $markdown;

            return;
        }
        $last = array_key_last($this->components);
        $current = $this->components[$last];
        $this->components[$last] = match (true) {
            $current === null || $current === '' => $markdown,
            $markdown === '' => $current,
            default => $current . "\n\n" . $markdown,
        };
    }

    /** Stores a block and returns the HTML element that stands in for it. */
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
}
