<?php

declare(strict_types=1);

namespace Preflow\Ai;

/** What may be shown about the connection. Holds whether a key exists, never the key. */
final readonly class AiStatus
{
    public function __construct(
        public bool $enabled,
        public string $provider,
        public string $model,
        public bool $keyPresent,
    ) {}
}
