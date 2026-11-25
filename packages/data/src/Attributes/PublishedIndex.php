<?php

declare(strict_types=1);

namespace Preflow\Data\Attributes;

/**
 * A composite index only the published copy of a type gets: `#[PublishedIndex('slug', 'state')]`
 * (as `"published_indexes": [["slug", "state"]]` in a model file). Repeatable. For lookups the
 * public site makes on published records (a type with publishing); the working table, which only
 * editors read, does not carry it. A type that does not publish and declares one is refused at boot.
 *
 * @since 0.89.0
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class PublishedIndex
{
    /** @var list<string> */
    public readonly array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = array_values($fields);
    }
}
