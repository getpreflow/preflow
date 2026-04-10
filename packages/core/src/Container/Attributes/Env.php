<?php

declare(strict_types=1);

namespace Preflow\Core\Container\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Env
{
    public function __construct(
        public string $name,
        public ?string $default = null,
    ) {}
}
