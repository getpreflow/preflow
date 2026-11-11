<?php

declare(strict_types=1);

namespace Preflow\Ai;

/**
 * What may be shown about the connection. Holds whether a key exists, never the key.
 * `available`: the provider says it can run (the built-ins: their key is present). `usesKey`:
 * config/ai.php `keys` names an environment variable for the provider ('fake' and a local server
 * may have none). Both since 0.66.
 */
final readonly class AiStatus
{
    public function __construct(
        public bool $enabled,
        public string $provider,
        public string $model,
        public bool $keyPresent,
        public bool $available = false,
        public bool $usesKey = true,
    ) {}
}
