<?php

declare(strict_types=1);

namespace Preflow\Folio\Seo;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\FrontendRoute;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\Publisher;
use Preflow\View\Geo\GeoConfig;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\SeoConfig;

/**
 * Folio's published pages as public pages (sitemap, llms.txt): the published
 * records of every type with a public frontend route, at their URL in a
 * locale. With SEO on, every such type is listed in the sitemap (or those in
 * `listed_types`); with GEO on, the types `config/geo.php` lists are in
 * llms.txt. Types behind a frontend permission, records not visible in the
 * locale, records hidden from search engines ("SEO & sharing", or GEO's
 * `noindex_field`) are left out; the home page comes first.
 */
final class FolioPublicPages
{
    public function __construct(
        private readonly ?SeoConfig $seo,
        private readonly ?GeoConfig $geo,
        private readonly TypeCatalog $catalog,
        private readonly Publisher $publisher,
        private readonly FrontendResolver $resolver,
    ) {}

    /** @return list<PublicPage> */
    public function __invoke(string $locale): array
    {
        $pages = [];
        foreach ($this->catalog->all() as $listing) {
            $sitemap = $this->inSitemap($listing->key);
            $llms = $this->inLlms($listing->key);
            $route = $this->resolver->routeFor($listing->key);
            if ((!$sitemap && !$llms) || $route === null || $route->permission !== null) {
                continue;
            }

            foreach ($this->records($listing->key, $route) as $record) {
                $page = $this->page($record, $locale, self::titleField($record->getType()->fields, $listing->list), $sitemap, $llms);
                if ($page !== null) {
                    $pages[] = $page;
                }
            }
        }

        return $pages;
    }

    /** @return list<DynamicRecord> the type's published records */
    private function records(string $type, FrontendRoute $route): array
    {
        $query = $this->publisher->query($type);
        if (!$route->publishing) {
            $query = $query->where($route->publishedField, $route->publishedValue);
        }

        return $query->get()->items();
    }

    private function inSitemap(string $type): bool
    {
        return $this->seo?->enabled() === true
            && ($this->seo->listedTypes === null || in_array($type, $this->seo->listedTypes, true));
    }

    private function inLlms(string $type): bool
    {
        return $this->geo?->enabled() === true && in_array($type, $this->geo->types, true);
    }

    private function page(DynamicRecord $record, string $locale, ?string $titleField, bool $sitemap, bool $llms): ?PublicPage
    {
        $seo = SeoSection::fromRecord($record);
        $values = $record->localized($locale);
        $noindexField = $this->geo?->noindexField;
        if ($seo->noindex() || ($noindexField !== null && isset($record->getType()->fields[$noindexField]) && self::truthy($values->get($noindexField)))) {
            return null;
        }
        $url = $this->resolver->urlFor($record, $locale);
        if ($url === null) {
            return null; // not visible in this locale
        }

        $title = $titleField !== null ? FolioPageMeta::text($values->get($titleField)) : '';
        $home = $this->resolver->pathFor($record, $locale) === '/';
        // The URLs the page itself names as canonical and hreflang ("/de" for a home page);
        // GeoEntries adds the slash llms.txt needs for "/de/index.md".
        $alternates = [];
        foreach ($record->getType()->locales->available as $code) {
            $alternate = $this->resolver->urlFor($record, $code);
            if ($alternate !== null) {
                $alternates[$code] = $alternate;
            }
        }
        $publishedAt = $record->get('_published_at');
        $llms = $llms && $seo->inLlms();

        return new PublicPage(
            title: $title !== '' ? $title : $url,
            url: $url,
            description: isset($record->getType()->fields['meta_description']) ? FolioPageMeta::text($values->get('meta_description')) : '',
            order: $home ? 0 : 100,
            lastmod: is_string($publishedAt) && $publishedAt !== '' ? $publishedAt : null,
            // One language needs no alternates.
            alternates: count($alternates) > 1 ? $alternates : [],
            sitemap: $sitemap && $seo->inSitemap(),
            llms: $llms,
            llmsFull: $llms,
        );
    }

    /**
     * The field a record's title comes from: `title`, else `name`, else the first list column.
     *
     * @param array<string, mixed> $fields the type's fields
     * @param array<string, mixed> $list   its admin list config
     */
    private static function titleField(array $fields, array $list): ?string
    {
        foreach (['title', 'name'] as $field) {
            if (isset($fields[$field])) {
                return $field;
            }
        }
        $first = ((array) ($list['columns'] ?? []))[0] ?? null;

        return is_string($first) ? $first : null;
    }

    private static function truthy(mixed $value): bool
    {
        return is_string($value)
            ? !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true)
            : (bool) $value;
    }
}
