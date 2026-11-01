<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * One public page, listed once for the sitemap and llms.txt: title, URL
 * (absolute, or a path on this site), description, section and order in
 * llms.txt, last change (DATE_ATOM), the other locales' URLs, and which
 * channels list it.
 */
final readonly class PublicPage
{
    /** @param array<string, string> $alternates locale => URL */
    public function __construct(
        public string $title,
        public string $url,
        public string $description = '',
        public string $section = 'Pages',
        public int $order = 100,
        public ?string $lastmod = null,
        public array $alternates = [],
        public bool $sitemap = true,
        public bool $llms = true,
        public bool $llmsFull = true,
    ) {}

    /** The same page at another URL. */
    public function withUrl(string $url): self
    {
        return $this->with(['url' => $url]);
    }

    /**
     * The same page with constructor arguments changed, by name.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
