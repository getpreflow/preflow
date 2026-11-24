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
        /** Issued by ComponentToken::encodeFor() for a component with state: props hold only its key, the state travels beside it (since 0.86.0). */
        public bool $stateCarried = false,
    ) {}
}
