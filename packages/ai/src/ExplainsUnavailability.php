<?php

declare(strict_types=1);

namespace Preflow\Ai;

/**
 * An AiClientFactory that can say why it is not available. `Ai::status()` passes the reason on,
 * and `ai:check` and Folio's Settings → AI show it. Never put the key in it.
 *
 * @since 0.66
 */
interface ExplainsUnavailability
{
    /**
     * Why available() said no for these options; null when it says yes or has nothing to add.
     *
     * @param array<string, mixed> $options
     */
    public function unavailableReason(#[\SensitiveParameter] array $options): ?string;
}
