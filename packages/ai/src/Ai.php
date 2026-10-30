<?php

declare(strict_types=1);

namespace Preflow\Ai;

use Preflow\Ai\Providers\ClaudeClient;
use Preflow\Ai\Providers\GeminiClient;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageLog;

/**
 * The entry point other code asks for an AI client. AI is on only when config/ai.php says
 * `enabled` AND the chosen provider's key is in the environment: the flag is the owner's
 * decision, the key is what makes it possible. Keys are read from the environment only and
 * never leave this class (status() reports just whether one exists).
 */
final class Ai
{
    /** @var \Closure(string): ?string */
    private readonly \Closure $env;

    /** @var \Closure(string, string, string): AiClient provider, model, key */
    private readonly \Closure $clientFactory;

    private ?AiClient $client = null;

    /**
     * @param array<string, mixed> $config config/ai.php
     * @param (\Closure(string): ?string)|null $env reads an env var; tests inject one
     * @param (\Closure(string, string, string): AiClient)|null $clientFactory builds the provider client from provider, model, key; tests inject one
     */
    public function __construct(
        private readonly array $config,
        private readonly UsageLog $usage,
        private readonly Pricing $pricing,
        ?\Closure $env = null,
        ?\Closure $clientFactory = null,
    ) {
        $this->env = $env ?? static function (string $name): ?string {
            // Some hosts load .env only into $_ENV, so getenv() alone misses the key.
            $value = getenv($name);
            if (!is_string($value) || $value === '') {
                $value = $_ENV[$name] ?? null;
            }

            return is_string($value) ? $value : null;
        };
        $this->clientFactory = $clientFactory ?? static fn (string $provider, string $model, #[\SensitiveParameter] string $key): AiClient => match ($provider) {
            'claude' => ClaudeClient::fromKey($key, $model),
            'gemini' => new GeminiClient($key, $model),
            default => throw new AiUnavailable("Unknown AI provider \"{$provider}\"."),
        };
    }

    public function enabled(): bool
    {
        return $this->switchedOn() && $this->key() !== null;
    }

    /**
     * The owner's switch in config/ai.php alone, whether or not a key exists. The settings
     * screens use it to stay reachable when the switch is on but the key is missing.
     */
    public function switchedOn(): bool
    {
        return ($this->config['enabled'] ?? false) === true;
    }

    public function status(): AiStatus
    {
        return new AiStatus($this->enabled(), $this->provider(), $this->model(), $this->key() !== null);
    }

    /** @throws AiUnavailable when AI is off or the key is missing */
    public function client(): AiClient
    {
        $key = $this->key();
        if (!$this->enabled() || $key === null) {
            throw new AiUnavailable('AI is not enabled (config/ai.php "enabled" and the provider key).');
        }

        return $this->client ??= new LoggedAiClient(
            ($this->clientFactory)($this->provider(), $this->model(), $key),
            $this->usage,
            $this->pricing,
        );
    }

    public function pricing(): Pricing
    {
        return $this->pricing;
    }

    private function provider(): string
    {
        $provider = $this->config['provider'] ?? 'claude';

        return is_string($provider) ? $provider : 'claude';
    }

    private function model(): string
    {
        $model = $this->config['models'][$this->provider()] ?? '';

        return is_string($model) ? $model : '';
    }

    private function key(): ?string
    {
        $name = $this->config['keys'][$this->provider()] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }
        $value = ($this->env)($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
