<?php

declare(strict_types=1);

namespace Preflow\Htmx;

final readonly class TokenPayload
{
    /**
     * @param array<string, mixed> $props
     */
    public function __construct(
        public string $componentClass,
        public array $props,
        public string $action,
        public int $timestamp,
    ) {}
}
