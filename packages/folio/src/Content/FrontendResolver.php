<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Publishing\ShadowTypes;

/**
 * Maps a public path to a record of any type that declares a "frontend" route. Routes are tried
 * most specific first; '' resolves to the record named by the (single) "home" declaration.
 *
 * match() reads the records as the current render's read mode says (since 0.81.0, through
 * LiveContent): the published snapshots of publishing types for a visitor, their working copies
 * in a whole-page preview (so the page and folio_find() show the same version). matchPublished()
 * always reads the published site, whatever the read mode: what a visitor would find at an
 * address (redirect targets, the SEO audit's pages).
 *
 * For the page cache, a page found this way depends on its record (by id) and on its address
 * (DataTags::address() of the path), not on the whole published table its lookup queried: the
 * Publisher reports the addresses a record leaves and takes. Publishing another record of the
 * type leaves the page alone; a record that takes over the address forgets it. Every publishing
 * route the path tries adds "any record of its table" (DataTags::anyRecord()): table and bulk
 * writes around the Publisher (imports) reach the page too. Routes of types
 * without publishing still depend on their table (their records are written without the
 * Publisher).
 */
final class FrontendResolver
{
    /** @var list<FrontendRoute> */
    private readonly array $routes;

    /**
     * @param string|list<FrontendRoute> $routes a type key means the legacy single
     *                                           route: that type at "/{slug}", home "home"
     */
    public function __construct(
        private readonly DataManager $dm,
        string|array $routes = 'page',
        private readonly ?\Preflow\Core\PageCache\PageCache $pageCache = null,
        /** The site's locale URL rule (core binds it from config/i18n.php); null: today's rule, the default unprefixed */
        private readonly ?\Preflow\Core\Http\LocaleUrls $urls = null,
        /** since 0.81.0: whether the current render reads working copies (match()); null reads the published site, as before 0.81 */
        private readonly ?\Preflow\Publishing\LiveContent $live = null,
    ) {
        $this->routes = self::ordered(is_string($routes) ? [self::legacy($routes)] : $routes);
    }

    /** @throws FrontendConfigException on duplicate routes or home declarations */
    public static function fromCatalog(TypeCatalog $catalog, DataManager $dm): self
    {
        return new self($dm, self::routesFromCatalog($catalog));
    }

    /**
     * The routes declared by the catalog's types (legacy "page" route when none
     * declares one). Needs no storage, so it can validate config at boot.
     *
     * @return list<FrontendRoute>
     * @throws FrontendConfigException on duplicate routes or home declarations
     */
    public static function routesFromCatalog(TypeCatalog $catalog): array
    {
        $routes = [];
        $byShape = [];
        $home = null;

        foreach ($catalog->all() as $listing) {
            if ($listing->frontend === null) {
                continue;
            }
            $route = FrontendRoute::fromConfig($listing->key, $listing->frontend, $listing->publishing);

            if (isset($byShape[$route->shape()])) {
                throw new FrontendConfigException(sprintf(
                    'Folio types %s both declare the frontend route "%s".',
                    self::pair($byShape[$route->shape()], $route->type),
                    $route->pattern,
                ));
            }
            if ($route->home !== null && $home !== null) {
                throw new FrontendConfigException(sprintf(
                    'Folio types %s both declare frontend.home.',
                    self::pair($home, $route->type),
                ));
            }

            $byShape[$route->shape()] = $route->type;
            $home = $route->home !== null ? $route->type : $home;
            $routes[] = $route;
        }

        if ($routes === [] && $catalog->has('page')) {
            return [self::legacy('page')];
        }

        return $routes;
    }

    public function resolve(string $path, ?string $locale = null): ?DynamicRecord
    {
        return $this->match($path, $locale)?->record;
    }

    /**
     * The record at $path as the current render reads it: the published snapshot of a publishing
     * type, or its working copy where the render reads working copies (a whole-page preview).
     *
     * @param string|null $locale the request's locale (from LocaleMiddleware); translated
     *                            route values are matched in it, and records not
     *                            visible in it (_locales) are skipped
     */
    public function match(string $path, ?string $locale = null): ?ResolvedRecord
    {
        return $this->find($path, $locale, onlyPublished: false);
    }

    /**
     * The record a visitor finds at $path: always the published snapshot of a publishing type,
     * whatever the render reads.
     *
     * @since 0.81.0
     */
    public function matchPublished(string $path, ?string $locale = null): ?ResolvedRecord
    {
        return $this->find($path, $locale, onlyPublished: true);
    }

    private function find(string $path, ?string $locale, bool $onlyPublished): ?ResolvedRecord
    {
        $path = trim($path, '/');
        $found = null;

        foreach ($this->routes as $route) {
            $localized = $locale !== null;
            if ($path === '') {
                if ($route->home === null || $route->paramNames === []) {
                    continue;
                }
                // The home record is identified by its default-locale value in every locale.
                $params = [$route->paramNames[0] => $route->home];
                $localized = false;
            } elseif (preg_match($route->regex, '/' . $path, $m) === 1) {
                $params = array_intersect_key($m, array_flip($route->paramNames));
            } else {
                continue;
            }

            // A publishing type's published snapshots, or its working copies (drafts too) where the render reads them.
            $published = $route->publishing && ($onlyPublished || !$this->readsWorkingCopies($route->type))
                ? ShadowTypes::published($this->definition($route->type))
                : null;
            if ($published !== null) {
                // Any route this path tries could claim it: a table or bulk write of its records
                // (an import, a pull, a site's raw write, none through the Publisher) changes the
                // page, whether this route finds a record now or not. A single record's write
                // through the Publisher never touches this tag (its address tags count instead).
                $this->pageCache?->depends(\Preflow\Core\PageCache\DataTags::anyRecord($published->storage, $published->table));
            }
            $query = $published !== null
                ? $this->dm->queryIn($published)->unreported() // the record and the address are reported below
                : $this->dm->queryType($route->type);
            if ($localized) {
                $query = $query->locale($locale);
            }
            if (!$route->publishing) {
                $query = $route->published($query, $this->definition($route->type));
            }
            foreach ($params as $field => $value) {
                $query = $query->where($field, $value);
            }

            foreach ($query->get()->items() as $record) {
                if ($locale === null || $record->getType()->translatableFields() === [] || in_array($locale, $record->availableLocales(), true)) {
                    $found = new ResolvedRecord($record, $route);
                    if ($published !== null) {
                        $this->dm->reportRead($published->storage, $published->table, (string) $record->getId());
                    }
                    break 2;
                }
            }
        }

        // Found or not: a record published at this address later changes what it shows.
        $this->pageCache?->depends(\Preflow\Core\PageCache\DataTags::address('/' . $path));

        return $found;
    }

    /**
     * The addresses of a published record, as DataTags::address() tags: its path in every
     * locale of its type it has one in (the Publisher reports those it leaves and takes).
     *
     * @return list<string>
     */
    public function addressTags(?DynamicRecord $record): array
    {
        if ($record === null) {
            return [];
        }
        $tags = [];
        foreach ($record->getType()->locales->available as $locale) {
            $path = $this->pathFor($record, $locale);
            if ($path !== null) {
                $tags[] = \Preflow\Core\PageCache\DataTags::address($path);
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * The public URL of a record in a locale: route parameters filled with the
     * locale's own values, behind the locale's prefix ("/de/…"; the default
     * locale's "/en/…" only with config/i18n.php prefix_default). Null when the
     * record isn't visible in that locale or a parameter has no value in it.
     */
    public function urlFor(DynamicRecord $record, string $locale): ?string
    {
        $path = $this->pathFor($record, $locale);
        if ($path === null) {
            return null;
        }
        if ($this->urls !== null) {
            return $this->urls->path($locale, $path);
        }
        if ($locale === $record->getType()->locales->default) {
            return $path;
        }

        return '/' . $locale . ($path !== '/' ? $path : '');
    }

    /**
     * The prefix a locale's URLs carry ("/de"; "/en" for the default locale with prefix_default;
     * '' for none), as urlFor() puts it on.
     *
     * Without the site's LocaleUrls (a resolver built by hand; core 0.78 always binds it): '' for
     * the default locale of the first routed type, else "/{locale}". urlFor() takes the record's own
     * type's default instead, so the two agree only while every routed type has the site's
     * locales, as the types of one site do.
     */
    public function localePrefix(string $locale): string
    {
        if ($this->urls !== null) {
            return $this->urls->prefix($locale);
        }
        $route = $this->routes[0] ?? null;
        $default = $route !== null ? $this->definition($route->type)->locales->default : null;

        return $locale === $default ? '' : '/' . $locale;
    }

    /**
     * The record's path as the router sees it after the locale middleware has
     * stripped the "/de" prefix: "/hallo", or "/" for the home record. Null in
     * the same cases as urlFor().
     */
    public function pathFor(DynamicRecord $record, string $locale): ?string
    {
        $route = $this->routeFor($record->getType()->key);
        $locales = $record->getType()->locales;
        if ($route === null || !$locales->has($locale)) {
            return null;
        }
        $multilingual = $record->getType()->translatableFields() !== [];
        if ($multilingual && !in_array($locale, $record->availableLocales(), true)) {
            return null;
        }

        $translatable = array_flip($record->getType()->translatableFields());
        $isHome = false;
        if ($route->home !== null && $route->paramNames !== []) {
            $first = $route->paramNames[0];
            $homeValue = isset($translatable[$first])
                ? LocaleValue::pick($record->get($first), $locales, $locales->default)
                : LocaleValue::default($record->get($first), $locales);
            $isHome = $homeValue === $route->home;
        }

        $path = '';
        if (!$isHome) {
            $path = $route->pattern;
            foreach ($route->paramNames as $name) {
                $raw = $record->get($name);
                $value = isset($translatable[$name]) ? LocaleValue::pick($raw, $locales, $locale) : LocaleValue::default($raw, $locales);
                if (!is_scalar($value) || (string) $value === '') {
                    return null;
                }
                $encoded = implode('/', array_map('rawurlencode', explode('/', (string) $value)));
                $path = (string) preg_replace('/\{(\.\.\.)?' . preg_quote($name, '/') . '\}/', $encoded, $path);
            }
            $path = trim($path, '/');
        }

        return '/' . $path;
    }

    public function routeFor(string $type): ?FrontendRoute
    {
        foreach ($this->routes as $route) {
            if ($route->type === $type) {
                return $route;
            }
        }

        return null;
    }

    private static function legacy(string $type): FrontendRoute
    {
        return FrontendRoute::fromConfig($type, ['route' => '/{slug}', 'home' => 'home']);
    }

    /**
     * More static segments first, then non-catch-all, then longer patterns.
     *
     * @param list<FrontendRoute> $routes
     * @return list<FrontendRoute>
     */
    private static function ordered(array $routes): array
    {
        usort($routes, static fn (FrontendRoute $a, FrontendRoute $b): int =>
            [$b->staticSegments, (int) !$b->isCatchAll, strlen($b->pattern)]
            <=> [$a->staticSegments, (int) !$a->isCatchAll, strlen($a->pattern)]);

        return $routes;
    }

    private static function pair(string $a, string $b): string
    {
        $types = [$a, $b];
        sort($types);

        return sprintf('"%s" and "%s"', ...$types);
    }

    /** Whether the current render reads $type's working copies (LiveContent); never without it. */
    private function readsWorkingCopies(string $type): bool
    {
        return $this->live?->readsWorkingCopies($type) ?? false;
    }

    /** The definition of a routed type (for FrontendRoute::published()). @internal */
    public function definition(string $type): \Preflow\Data\TypeDefinition
    {
        $registry = $this->dm->getTypeRegistry() ?? throw new \LogicException('Folio frontend routes need a TypeRegistry.');

        return $registry->get($type);
    }
}
