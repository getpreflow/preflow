<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

/**
 * One page listed in llms.txt: its title, page URL (absolute, or a path on
 * this site), description, section and order there, and whether
 * llms-full.txt includes its Markdown.
 */
final readonly class GeoEntry
{
    public function __construct(
        public string $title,
        public string $url,
        public string $description = '',
        public string $section = 'Pages',
        public int $order = 100,
        public bool $full = true,
    ) {}

    /** The same entry at another URL. */
    public function withUrl(string $url): self
    {
        return new self($this->title, $url, $this->description, $this->section, $this->order, $this->full);
    }

    /** The page's Markdown URL: `…/about` → `…/about.md`, `…/` → `…/index.md`. */
    public function markdownUrl(): string
    {
        $url = $this->url;
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]*$#i', $url) === 1) {
            $url .= '/';
        }

        return GeoMiddleware::markdownUrl($url);
    }
}
