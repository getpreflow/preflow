<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;

/**
 * Maps a public path to a published record of any type that declares a
 * "frontend" route. Routes are tried most specific first; '' resolves to the
 * record named by the (single) "home" declaration.
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
            $route = FrontendRoute::fromConfig($listing->key, $listing->frontend);

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
     * @param string|null $locale the request's locale (from LocaleMiddleware); translated
     *                            route values are matched in it, and records not
     *                            visible in it (_locales) are skipped
     */
    public function match(string $path, ?string $locale = null): ?ResolvedRecord
    {
        $path = trim($path, '/');

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

            $query = $this->dm->queryType($route->type);
            if ($localized) {
                $query = $query->locale($locale);
            }
            $query = $query->where($route->publishedField, $route->publishedValue);
            foreach ($params as $field => $value) {
                $query = $query->where($field, $value);
            }

            foreach ($query->get()->items() as $record) {
                if ($locale === null || $record->getType()->translatableFields() === [] || in_array($locale, $record->availableLocales(), true)) {
                    return new ResolvedRecord($record, $route);
                }
            }
        }

        return null;
    }

    /**
     * The public URL of a record in a locale: route parameters filled with the
     * locale's own values, "/de/…" for non-default locales. Null when the
     * record isn't visible in that locale or a parameter has no value in it.
     */
    public function urlFor(DynamicRecord $record, string $locale): ?string
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

        if ($locale === $locales->default) {
            return '/' . $path;
        }

        return '/' . $locale . ($path !== '' ? '/' . $path : '');
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
}
