<?php

declare(strict_types=1);

namespace Preflow\View;

final readonly class TemplateFunctionDefinition
{
    public function __construct(
        public string $name,
        public \Closure $callable,
        public bool $isSafe = false,
    ) {}
}
