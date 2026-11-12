<?php

declare(strict_types=1);

namespace Preflow\Folio\Redirects;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Routing\Redirect\TargetIsLive;

/**
 * A record as the public site shows it, by id: the published snapshot of a publishing type,
 * else the record when its "published" field has the route's value (queried as
 * FrontendResolver::match() queries it). Null for a type without a frontend route.
 */
final class LiveRecords implements TargetIsLive
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly Publisher $publisher,
        private readonly FrontendResolver $resolver,
    ) {}

    public function isLive(string $type, string $id): bool
    {
        return $this->find($type, $id) !== null;
    }

    public function find(string $type, string $id): ?DynamicRecord
    {
        $route = $this->resolver->routeFor($type);
        if ($route === null) {
            return null;
        }
        if ($route->publishing) {
            return $this->publisher->snapshot($type, $id);
        }

        $idField = $this->dm->getTypeRegistry()?->get($type)->idField ?? 'uuid';
        $records = $this->dm->queryType($type)
            ->where($idField, $id)
            ->where($route->publishedField, $route->publishedValue)
            ->get()->items();
        foreach ($records as $record) {
            // The id is a value column here, which MySQL compares case-insensitively: exact matches only.
            if ((string) $record->getId() === $id) {
                return $record;
            }
        }

        return null;
    }

    /** @return list<DynamicRecord> every record of the type the public site shows; none for a type without a frontend route */
    public function of(string $type): array
    {
        $route = $this->resolver->routeFor($type);
        if ($route === null) {
            return [];
        }
        if ($route->publishing) {
            return array_values($this->publisher->query($type)->get()->items());
        }
        // Queried as FrontendResolver::match() queries it: what this returns is what the site shows.
        return array_values($this->dm->queryType($type)->where($route->publishedField, $route->publishedValue)->get()->items());
    }
}
