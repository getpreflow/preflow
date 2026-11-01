<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * The site's public pages, one list for the sitemap and llms.txt: sources add
 * pages per locale, filters drop, change or reorder them.
 *
 *     $container->get(PublicPages::class)->source(fn (string $locale) => [new PublicPage(…)]);
 *     $container->get(PublicPages::class)->filter(fn (array $pages, string $locale) => …);
 */
final class PublicPages
{
    /** @var list<callable(string): iterable<PublicPage>> */
    private array $sources = [];

    /** @var list<callable(list<PublicPage>, string): array<PublicPage>> */
    private array $filters = [];

    /** @param callable(string $locale): iterable<PublicPage> $source */
    public function source(callable $source): void
    {
        $this->sources[] = $source;
    }

    /** @param callable(list<PublicPage> $pages, string $locale): array<PublicPage> $filter */
    public function filter(callable $filter): void
    {
        $this->filters[] = $filter;
    }

    /**
     * Every source's pages for a locale, filtered, then sorted: sections in
     * the order they first appear (the home page's — order 0 — first), within
     * a section by order, then title.
     *
     * @return list<PublicPage>
     */
    public function for(string $locale): array
    {
        $pages = [];
        foreach ($this->sources as $source) {
            foreach ($source($locale) as $page) {
                $pages[] = self::page($page);
            }
        }
        foreach ($this->filters as $filter) {
            $pages = array_map(self::page(...), array_values($filter($pages, $locale)));
        }

        return PageOrder::sort($pages);
    }

    /** @return list<PublicPage> the pages the sitemap lists */
    public function sitemap(string $locale): array
    {
        return array_values(array_filter($this->for($locale), static fn (PublicPage $p): bool => $p->sitemap));
    }

    /** @return list<PublicPage> the pages llms.txt lists */
    public function llms(string $locale): array
    {
        return array_values(array_filter($this->for($locale), static fn (PublicPage $p): bool => $p->llms));
    }

    private static function page(mixed $page): PublicPage
    {
        if (!$page instanceof PublicPage) {
            throw new \UnexpectedValueException('Public page sources and filters must give PublicPage objects, got ' . get_debug_type($page) . '.');
        }

        return $page;
    }
}
