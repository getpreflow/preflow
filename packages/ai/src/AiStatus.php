<?php

declare(strict_types=1);

namespace Preflow\Ai;

/**
 * What may be shown about the connection. Holds whether a key exists, never the key.
 * `available`: the provider says it can run (the built-ins: their key is present); when not
 * given, it is taken to be `enabled`. `usesKey`: config/ai.php `keys` names an environment
 * variable for the provider ('fake' and a local server may have none). `reason`: why the provider
 * is not available, when it says (ExplainsUnavailability) or its check failed. All since 0.66.
 */
final readonly class AiStatus
{
    public bool $available;

    public function __construct(
        public bool $enabled,
        public string $provider,
        public string $model,
        public bool $keyPresent,
        ?bool $available = null,
        public bool $usesKey = true,
        public ?string $reason = null,
    ) {
        $this->available = $available ?? $enabled;
    }
}
