<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

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

    public function resolve(string $path): ?DynamicRecord
    {
        return $this->match($path)?->record;
    }

    public function match(string $path): ?ResolvedRecord
    {
        $path = trim($path, '/');

        foreach ($this->routes as $route) {
            if ($path === '') {
                if ($route->home === null || $route->paramNames === []) {
                    continue;
                }
                $params = [$route->paramNames[0] => $route->home];
            } elseif (preg_match($route->regex, '/' . $path, $m) === 1) {
                $params = array_intersect_key($m, array_flip($route->paramNames));
            } else {
                continue;
            }

            $query = $this->dm->queryType($route->type)->where($route->publishedField, $route->publishedValue);
            foreach ($params as $field => $value) {
                $query = $query->where($field, $value);
            }

            $record = $query->first();
            if ($record !== null) {
                return new ResolvedRecord($record, $route);
            }
        }

        return null;
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
