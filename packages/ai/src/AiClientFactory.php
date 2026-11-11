<?php

declare(strict_types=1);

namespace Preflow\Ai;

/**
 * Builds the client of one AI provider. config/ai.php `provider` names a built-in ('claude',
 * 'gemini', 'fake') or the class of a factory of your own, which the container builds (so its
 * constructor may take services and #[Config] values).
 *
 * $options is the provider's entry in config/ai.php `options` (keyed by the provider's name or
 * class), plus `model` (its `models` entry, '' when none) and `key` (the value of the environment
 * variable its `keys` entry names, null when it names none or the variable is empty). The key is
 * never logged or shown: keep it out of exception messages. PHP does not inherit
 * #[\SensitiveParameter] from an interface: repeat it on your available() and create(), or a
 * debug page's stack trace shows the key. A factory that can say why it is not available also
 * implements ExplainsUnavailability.
 *
 * @since 0.66
 */
interface AiClientFactory
{
    /**
     * Whether the provider can be used now. AI is on when config/ai.php says `enabled` and this
     * says yes: the built-ins need their key; a local server may need none. Keep it cheap (it is
     * asked on admin requests); an exception here counts as "not available", with its message
     * as the reason.
     *
     * @param array<string, mixed> $options
     */
    public function available(#[\SensitiveParameter] array $options): bool;

    /**
     * Called once, after available() said yes.
     *
     * @param array<string, mixed> $options
     * @throws AiUnavailable when the provider can't be used after all
     */
    public function create(#[\SensitiveParameter] array $options): AiClient;
}
