<?php

declare(strict_types=1);

namespace Preflow\Ai;

use Preflow\Ai\Providers\ClaudeClientFactory;
use Preflow\Ai\Providers\GeminiClientFactory;
use Preflow\Ai\Testing\FakeAiClientFactory;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageLog;

/**
 * The entry point other code asks for an AI client. AI is on only when config/ai.php says
 * `enabled` AND the chosen provider is available: the flag is the owner's decision, the provider
 * says whether it can run (the built-ins need their key in the environment; 'fake' and a local
 * server need none). config/ai.php `provider` is 'claude', 'gemini', 'fake' or the class of an
 * AiClientFactory. Keys are read from the environment only and never leave this class and the
 * provider's factory (status() reports just whether one exists).
 */
final class Ai
{
    /** The built-in provider names and their factories. */
    public const array PROVIDERS = [
        'claude' => ClaudeClientFactory::class,
        'gemini' => GeminiClientFactory::class,
        'fake' => FakeAiClientFactory::class,
    ];

    /** @var \Closure(string): ?string */
    private readonly \Closure $env;

    /** @var \Closure(class-string<AiClientFactory>): object */
    private readonly \Closure $make;

    private ?AiClientFactory $factory = null;

    /** Why the factory's availability check threw, if it did (once per instance). */
    private ?string $failure = null;

    private ?AiClient $client = null;

    /**
     * @param array<string, mixed> $config config/ai.php
     * @param (\Closure(string): ?string)|null $env reads an env var; tests inject one
     * @param (\Closure(string, string, string): AiClient)|null $clientFactory builds the client from provider, model, key in place
     *        of the provider's factory (tests); whether AI is available is still the factory's answer
     * @param (\Closure(class-string<AiClientFactory>): object)|null $make builds a provider's factory (AiServiceProvider: the container); default `new`
     */
    public function __construct(
        private readonly array $config,
        private readonly UsageLog $usage,
        private readonly Pricing $pricing,
        ?\Closure $env = null,
        private readonly ?\Closure $clientFactory = null,
        ?\Closure $make = null,
    ) {
        $this->env = $env ?? static function (string $name): ?string {
            // Some hosts load .env only into $_ENV, so getenv() alone misses the key.
            $value = getenv($name);
            if (!is_string($value) || $value === '') {
                $value = $_ENV[$name] ?? null;
            }

            return is_string($value) ? $value : null;
        };
        $this->make = $make ?? static fn (string $class): object => new $class();
    }

    /**
     * The factory class config/ai.php `provider` names: a built-in name or the class of an
     * AiClientFactory.
     *
     * @return class-string<AiClientFactory>
     * @throws \InvalidArgumentException naming the accepted forms
     */
    public static function factoryClass(string $provider): string
    {
        if (isset(self::PROVIDERS[$provider])) {
            return self::PROVIDERS[$provider];
        }
        $class = ltrim($provider, '\\');
        if ($class !== '' && class_exists($class) && is_subclass_of($class, AiClientFactory::class)) {
            return $class;
        }
        $what = $class !== '' && class_exists($class) ? "{$class} is not an " . AiClientFactory::class : "\"{$provider}\" is unknown";

        throw new \InvalidArgumentException("config/ai.php provider: {$what}. Use 'claude', 'gemini', 'fake' or the class of a " . AiClientFactory::class . '.');
    }

    public function enabled(): bool
    {
        return $this->switchedOn() && $this->available();
    }

    /**
     * The owner's switch in config/ai.php alone, whether or not the provider can run. The settings
     * screens use it to stay reachable when the switch is on but the key is missing.
     */
    public function switchedOn(): bool
    {
        return ($this->config['enabled'] ?? false) === true;
    }

    public function status(): AiStatus
    {
        $available = $this->available();

        return new AiStatus(
            $this->switchedOn() && $available,
            $this->provider(),
            $this->model(),
            $this->key() !== null,
            $available,
            $this->keyName() !== null,
            $available ? null : $this->reason(),
        );
    }

    /** @throws AiUnavailable when AI is off or the provider is not available */
    public function client(): AiClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if (!$this->enabled()) {
            throw new AiUnavailable('AI is not enabled (config/ai.php "enabled", and the provider must be available: the built-ins need their key).');
        }
        $options = $this->options();
        $client = $this->clientFactory !== null
            ? ($this->clientFactory)($this->provider(), $this->model(), (string) ($options['key'] ?? ''))
            : $this->factory()->create($options);
        if (!$client instanceof AiClient) {
            throw new AiUnavailable('The AI provider "' . $this->provider() . '" built no ' . AiClient::class . '.');
        }

        return $this->client = new LoggedAiClient($client, $this->usage, $this->pricing);
    }

    public function pricing(): Pricing
    {
        return $this->pricing;
    }

    /** The factory's answer; a check that throws counts as "not available", with its message as the reason. */
    private function available(): bool
    {
        if ($this->failure !== null) {
            return false;
        }
        $factory = $this->factory();
        try {
            return $factory->available($this->options());
        } catch (\Throwable $e) {
            $this->failure = 'The provider\'s availability check failed: ' . $e::class . ': ' . $e->getMessage();
            error_log('Preflow AI: ' . $this->failure);

            return false;
        }
    }

    private function reason(): ?string
    {
        if ($this->failure !== null) {
            return $this->failure;
        }
        $factory = $this->factory();
        if ($factory instanceof ExplainsUnavailability) {
            return $factory->unavailableReason($this->options());
        }

        return $this->keyName() !== null && $this->key() === null ? 'The provider\'s key is missing in the environment.' : null;
    }

    private function factory(): AiClientFactory
    {
        if ($this->factory === null) {
            $class = self::factoryClass($this->provider());
            $factory = ($this->make)($class);
            if (!$factory instanceof AiClientFactory) {
                throw new \InvalidArgumentException("config/ai.php provider: {$class} is not an " . AiClientFactory::class . '.');
            }
            $this->factory = $factory;
        }

        return $this->factory;
    }

    /** @return array<string, mixed> the provider's `options` entry, plus `model` and `key` */
    private function options(): array
    {
        $own = $this->config['options'][$this->provider()] ?? [];

        return ['model' => $this->model(), 'key' => $this->key()] + (is_array($own) ? $own : []);
    }

    private function provider(): string
    {
        $provider = $this->config['provider'] ?? 'claude';

        return is_string($provider) && $provider !== '' ? $provider : 'claude';
    }

    private function model(): string
    {
        // The fake names its model even without a `models` entry, so status screens show one.
        $model = $this->config['models'][$this->provider()] ?? ($this->provider() === 'fake' ? 'fake-model' : '');

        return is_string($model) ? $model : '';
    }

    private function keyName(): ?string
    {
        $name = $this->config['keys'][$this->provider()] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function key(): ?string
    {
        $name = $this->keyName();
        if ($name === null) {
            return null;
        }
        $value = ($this->env)($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
