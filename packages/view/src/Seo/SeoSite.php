<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * What a site adds to SEO while it registers, before the middleware exists:
 * its defaults per locale (site name as title, description, share image) and
 * extra robots.txt Disallow paths, with Allow exceptions under them. Folio sets
 * all three; a site without Folio can too.
 *
 *     $container->get(SeoSite::class)->defaults(fn (string $locale) => new PageMeta(title: 'Job Board', …));
 *     $container->get(SeoSite::class)->disallow('/admin/');
 *     $container->get(SeoSite::class)->allow('/admin/uploads/');
 */
final class SeoSite
{
    /** @var ?\Closure(string): ?PageMeta */
    private ?\Closure $defaults = null;

    /** @var list<string> */
    private array $disallow = [];

    /** @var list<string> */
    private array $allow = [];

    /** @param \Closure(string $locale): ?PageMeta $defaults a later call replaces an earlier one */
    public function defaults(\Closure $defaults): void
    {
        $this->defaults = $defaults;
    }

    public function defaultsFor(string $locale): ?PageMeta
    {
        return $this->defaults === null ? null : ($this->defaults)($locale);
    }

    public function disallow(string $path): void
    {
        if ($path !== '' && !in_array($path, $this->disallow, true)) {
            $this->disallow[] = $path;
        }
    }

    /** @return list<string> */
    public function disallowed(): array
    {
        return $this->disallow;
    }

    /** A path under a disallowed one that crawlers may still fetch (images a public page shows). */
    public function allow(string $path): void
    {
        if ($path !== '' && !in_array($path, $this->allow, true)) {
            $this->allow[] = $path;
        }
    }

    /** @return list<string> */
    public function allowed(): array
    {
        return $this->allow;
    }
}
