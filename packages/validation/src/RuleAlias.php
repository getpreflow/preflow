<?php

declare(strict_types=1);

namespace Preflow\Validation;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class RuleAlias
{
    public function __construct(
        public readonly string $alias,
    ) {}
}
