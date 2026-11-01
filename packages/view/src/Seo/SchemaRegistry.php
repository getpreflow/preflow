<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * Where sites add their own JSON-LD: nodes for records of a type (an Event,
 * a Product…) and the site owner when it isn't fixed in config.
 */
final class SchemaRegistry
{
    /** @var array<string, callable(array, string, PageMeta): array> */
    private array $types = [];

    /** @var ?callable(string): ?array */
    private $owner = null;

    /**
     * Build nodes for records of a type; a later builder for the same type
     * replaces the earlier one.
     *
     * @param callable(array $record, string $locale, PageMeta $meta): array $builder one node or a list of nodes
     */
    public function forType(string $type, callable $builder): void
    {
        $this->types[$type] = $builder;
    }

    /** @param callable(string $locale): ?array $builder the `owner` config shape; overrides config */
    public function owner(callable $builder): void
    {
        $this->owner = $builder;
    }

    /** @return list<array> */
    public function nodesFor(string $type, array $record, string $locale, PageMeta $meta): array
    {
        $builder = $this->types[$type] ?? null;
        $nodes = $builder === null ? [] : $builder($record, $locale, $meta);
        if ($nodes === []) {
            return [];
        }

        return array_is_list($nodes) ? array_values(array_filter($nodes, 'is_array')) : [$nodes];
    }

    /**
     * The site owner in a locale: the builder's answer when one is set,
     * else the owner from config.
     *
     * @param ?array $configOwner SeoConfig::$owner
     */
    public function ownerFor(string $locale, ?array $configOwner): ?array
    {
        return $this->owner === null ? $configOwner : ($this->owner)($locale);
    }
}
