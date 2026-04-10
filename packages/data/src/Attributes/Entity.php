<?php
declare(strict_types=1);
namespace Preflow\Data\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(
        public readonly string $table,
        public readonly string $storage = 'default',
    ) {}
}
