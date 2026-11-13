<?php

declare(strict_types=1);

namespace Preflow\Data\Attributes;

/**
 * A composite index over fields of the type, in this order: `#[Index('state', 'updated_at')]`
 * (as `"indexes": [["state", "updated_at"]]` in a model file). Repeatable.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Index
{
    /** @var list<string> */
    public readonly array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = array_values($fields);
    }
}
