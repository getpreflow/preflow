<?php
declare(strict_types=1);
namespace Preflow\Data\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(
        public readonly string $table,
        public readonly string $storage = 'default',
        /** The type key that makes this class a data type (as a model JSON file's `key`); null keeps it an ordinary model. */
        public readonly ?string $type = null,
    ) {}
}
