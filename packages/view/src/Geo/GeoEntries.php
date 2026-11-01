<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

use Preflow\View\Seo\PageOrder;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;

/**
 * Where llms.txt gets its pages: the public pages' llms channel, plus sources that add entries per locale, filters drop,
 * change or reorder them. Folio registers its published pages as a source;
 * sites add their own to PublicPages, which the sitemap reads too:
 *
 *     $container->get(PublicPages::class)->source(fn (string $locale) => [new PublicPage(…)]);
 *
 * Also holds the site's own fields (name, summary…) per locale, which llms.txt
 * falls back to when `config/geo.php` names none.
 */
final class GeoEntries
{
    /** @var list<callable(string): iterable<GeoEntry>> */
    private array $sources = [];

    /** @var list<callable(list<GeoEntry>, string): array<GeoEntry>> */
    private array $filters = [];

    /** @var ?callable(string): array<string, mixed> */
    private $site = null;

    public function __construct(private readonly ?PublicPages $pages = null) {}

    /**
     * @deprecated add pages to PublicPages::source() instead; they reach the sitemap too
     * @param callable(string $locale): iterable<GeoEntry> $source
     */
    public function source(callable $source): void
    {
        $this->sources[] = $source;
    }

    /**
     * @deprecated use PublicPages::filter() instead
     * @param callable(list<GeoEntry> $entries, string $locale): array<GeoEntry> $filter
     */
    public function filter(callable $filter): void
    {
        $this->filters[] = $filter;
    }

    /** @param callable(string $locale): array<string, mixed> $fields the site's fields in a locale */
    public function site(callable $fields): void
    {
        $this->site = $fields;
    }

    /** @return array<string, mixed> */
    public function siteFields(string $locale): array
    {
        return $this->site !== null ? ($this->site)($locale) : [];
    }

    /**
     * The public pages' llms channel and every source's entries for a locale, filtered, then sorted: sections in
     * the order they first appear (the home page's — order 0 — first), within a
     * section by order, then title.
     *
     * @return list<GeoEntry>
     */
    public function for(string $locale): array
    {
        $entries = array_map(
            static fn (PublicPage $p): GeoEntry => new GeoEntry($p->title, self::url($p), $p->description, $p->section, $p->order, $p->llmsFull),
            $this->pages?->llms($locale) ?? [],
        );
        foreach ($this->sources as $source) {
            foreach ($source($locale) as $entry) {
                $entries[] = self::entry($entry);
            }
        }
        foreach ($this->filters as $filter) {
            $entries = array_map(self::entry(...), array_values($filter($entries, $locale)));
        }

        return PageOrder::sort($entries);
    }

    /**
     * A public page's URL in llms.txt: a home page (order 0) keeps its canonical URL ("/de") in the
     * sitemap, but its Markdown is "/de/index.md", so it is listed as "/de/".
     */
    private static function url(PublicPage $page): string
    {
        return $page->order === 0 ? rtrim($page->url, '/') . '/' : $page->url;
    }

    private static function entry(mixed $entry): GeoEntry
    {
        if (!$entry instanceof GeoEntry) {
            throw new \UnexpectedValueException('GEO entry sources and filters must give GeoEntry objects, got ' . get_debug_type($entry) . '.');
        }

        return $entry;
    }
}
