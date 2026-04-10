<?php

declare(strict_types=1);

namespace Preflow\Core\Container\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Config
{
    public function __construct(
        public string $key,
        public mixed $default = null,
    ) {}
}
