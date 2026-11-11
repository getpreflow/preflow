<?php

declare(strict_types=1);

namespace Preflow\Ai\Providers;

use Preflow\Ai\AiClient;
use Preflow\Ai\AiClientFactory;

/** config/ai.php `'provider' => 'gemini'`: Google's Gemini API; needs the key `keys.gemini` names. */
final class GeminiClientFactory implements AiClientFactory
{
    public function available(#[\SensitiveParameter] array $options): bool
    {
        return is_string($options['key'] ?? null) && $options['key'] !== '';
    }

    public function create(#[\SensitiveParameter] array $options): AiClient
    {
        return new GeminiClient((string) $options['key'], (string) ($options['model'] ?? ''));
    }
}
