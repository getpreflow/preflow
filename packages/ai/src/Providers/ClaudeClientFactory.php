<?php

declare(strict_types=1);

namespace Preflow\Ai\Providers;

use Preflow\Ai\AiClient;
use Preflow\Ai\AiClientFactory;

/** config/ai.php `'provider' => 'claude'`: Anthropic's API through its SDK; needs the key `keys.claude` names. */
final class ClaudeClientFactory implements AiClientFactory
{
    public function available(#[\SensitiveParameter] array $options): bool
    {
        return is_string($options['key'] ?? null) && $options['key'] !== '';
    }

    public function create(#[\SensitiveParameter] array $options): AiClient
    {
        return ClaudeClient::fromKey((string) $options['key'], (string) ($options['model'] ?? ''));
    }
}
