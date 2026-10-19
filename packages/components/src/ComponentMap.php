<?php

declare(strict_types=1);

namespace Preflow\Components;

/** The components discovered under app/Components: short name => class. */
final readonly class ComponentMap
{
    /** @param array<string, class-string> $classes */
    public function __construct(
        public array $classes,
    ) {}

    /** @return array<string, class-string> */
    public function all(): array
    {
        return $this->classes;
    }
}
