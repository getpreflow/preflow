<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\Ai;
use Preflow\Ai\AiClient;
use Preflow\Ai\AiClientFactory;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Providers\ClaudeClientFactory;
use Preflow\Ai\Providers\GeminiClientFactory;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Ai\Testing\FakeAiClientFactory;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageEntry;
use Preflow\Ai\Usage\UsageLog;

/** config/ai.php "provider": the built-in names, "fake", or the class of an AiClientFactory (0.66). */
final class AiProvidersTest extends TestCase
{
    private function log(): UsageLog
    {
        return new class implements UsageLog {
            /** @var list<UsageEntry> */
            public array $entries = [];

            public function record(UsageEntry $e): void
            {
                $this->entries[] = $e;
            }

            public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return $this->entries;
            }
        };
    }

    /** @param array<string, mixed> $config */
    private function ai(array $config, ?UsageLog $log = null, ?\Closure $make = null): Ai
    {
        return new Ai(
            $config + ['enabled' => true],
            $log ?? $this->log(),
            Pricing::withOverrides([]),
            static fn (): ?string => null,
            make: $make,
        );
    }

    public function test_the_built_in_names_map_to_their_factories(): void
    {
        $this->assertSame(ClaudeClientFactory::class, Ai::factoryClass('claude'));
        $this->assertSame(GeminiClientFactory::class, Ai::factoryClass('gemini'));
        $this->assertSame(FakeAiClientFactory::class, Ai::factoryClass('fake'));
        $this->assertSame(LocalModelFactory::class, Ai::factoryClass(LocalModelFactory::class));
        $this->assertSame(LocalModelFactory::class, Ai::factoryClass('\\' . LocalModelFactory::class));
    }

    public function test_an_unknown_provider_is_refused_listing_the_accepted_forms(): void
    {
        try {
            Ai::factoryClass('openai');
            $this->fail('expected an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('"openai"', $e->getMessage());
            $this->assertStringContainsString("'claude', 'gemini', 'fake'", $e->getMessage());
            $this->assertStringContainsString(AiClientFactory::class, $e->getMessage());
        }
    }

    public function test_a_class_that_is_no_factory_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\stdClass::class);
        Ai::factoryClass(\stdClass::class);
    }

    public function test_the_fake_runs_without_a_key_and_answers_ok_by_default(): void
    {
        $log = $this->log();
        $ai = $this->ai(['provider' => 'fake', 'options' => ['fake' => ['allow_live' => true]]], $log);

        $this->assertTrue($ai->enabled());
        $status = $ai->status();
        $this->assertTrue($status->available);
        $this->assertFalse($status->keyPresent);
        $this->assertFalse($status->usesKey);
        $this->assertSame('fake', $status->provider);
        $this->assertSame('fake-model', $status->model);

        $response = $ai->client()->complete(new AiRequest('ai-check', 'Reply with {"ok":true}'));
        $this->assertSame(['ok' => true], $response->json());
        // The price table doesn't know the fake's model: the cost is unknown, not 0, and nothing breaks.
        $this->assertCount(1, $log->entries);
        $this->assertNull($log->entries[0]->costUsd);
    }

    public function test_the_fake_answers_what_config_says(): void
    {
        $ai = $this->ai([
            'provider' => 'fake',
            'models' => ['fake' => 'my-model'],
            'options' => ['fake' => ['answer' => ['summary' => 'A designer.'], 'usage' => [310, 42], 'allow_live' => true]],
        ]);

        $response = $ai->client()->complete(new AiRequest('job-summary', 'x'));

        $this->assertSame(['summary' => 'A designer.'], $response->json());
        $this->assertSame(310, $response->usage->input);
        $this->assertSame(42, $response->usage->output);
        $this->assertSame('my-model', $ai->client()->model());
    }

    public function test_the_fake_answers_text(): void
    {
        $ai = $this->ai(['provider' => 'fake', 'options' => ['fake' => ['answer' => 'Hello.', 'allow_live' => true]]]);

        $this->assertSame('Hello.', $ai->client()->complete(new AiRequest('x', 'y'))->text);
    }

    public function test_a_factory_class_is_built_by_the_resolver_with_its_options(): void
    {
        $made = [];
        $ai = $this->ai([
            'provider' => LocalModelFactory::class,
            'models' => [LocalModelFactory::class => 'llama-3'],
            'options' => [LocalModelFactory::class => ['url' => 'http://127.0.0.1:11434/v1']],
        ], null, static function (string $class) use (&$made): object {
            $made[] = $class;

            return new $class();
        });

        $this->assertTrue($ai->enabled(), 'a local endpoint needs no key: the factory says it is available');
        $client = $ai->client();
        $this->assertSame([LocalModelFactory::class], $made, 'built once');
        $this->assertSame('llama-3', $client->model());
        $this->assertSame(['model' => 'llama-3', 'key' => null, 'url' => 'http://127.0.0.1:11434/v1'], LocalModelFactory::$lastOptions);
        $ai->status();
        $this->assertSame([LocalModelFactory::class], $made);
    }

    public function test_a_factory_decides_availability(): void
    {
        $ai = $this->ai([
            'provider' => LocalModelFactory::class,
            'options' => [LocalModelFactory::class => ['url' => '']],
        ]);

        $this->assertFalse($ai->enabled());
        $this->assertTrue($ai->switchedOn());
        $this->assertFalse($ai->status()->available);
        $this->expectException(AiUnavailable::class);
        $ai->client();
    }

    public function test_a_factory_gets_the_key_its_entry_names(): void
    {
        $ai = new Ai(
            ['enabled' => true, 'provider' => LocalModelFactory::class, 'keys' => [LocalModelFactory::class => 'LOCAL_KEY'], 'options' => [LocalModelFactory::class => ['url' => 'http://x']]],
            $this->log(),
            Pricing::withOverrides([]),
            static fn (string $name): ?string => $name === 'LOCAL_KEY' ? 'k-123' : null,
        );

        $this->assertTrue($ai->status()->keyPresent);
        $this->assertTrue($ai->status()->usesKey);
        $ai->client();
        $this->assertSame('k-123', LocalModelFactory::$lastOptions['key']);
    }

    public function test_the_built_ins_still_need_their_key(): void
    {
        $ai = $this->ai(['provider' => 'gemini', 'keys' => ['gemini' => 'GEMINI_API_KEY']]);

        $this->assertFalse($ai->enabled());
        $this->assertFalse($ai->status()->available);
        $this->assertTrue($ai->status()->usesKey);
    }

    public function test_a_factory_that_builds_no_client_is_refused(): void
    {
        $ai = $this->ai(['provider' => LocalModelFactory::class, 'options' => [LocalModelFactory::class => ['url' => 'http://x', 'broken' => true]]]);

        $this->expectException(AiUnavailable::class);
        $ai->client();
    }

    public function test_the_fake_is_off_on_a_live_site_and_says_why(): void
    {
        $ai = $this->ai(['provider' => 'fake'], null, static fn (string $class): object => new $class(0));

        $this->assertTrue($ai->switchedOn());
        $this->assertFalse($ai->enabled(), 'app.debug off: no canned answers on a live site');
        $status = $ai->status();
        $this->assertFalse($status->available);
        $this->assertStringContainsString('allow_live', (string) $status->reason);
        $this->assertStringContainsString('app.debug', (string) $status->reason);
    }

    public function test_the_fake_answers_while_developing(): void
    {
        $ai = $this->ai(['provider' => 'fake'], null, static fn (string $class): object => new $class(1));

        $this->assertTrue($ai->enabled());
        $this->assertNull($ai->status()->reason);
        $this->assertSame(['ok' => true], $ai->client()->complete(new AiRequest('x', 'y'))->json());
    }

    public function test_the_fake_keeps_no_request_history(): void
    {
        $ai = $this->ai(['provider' => 'fake', 'options' => ['fake' => ['allow_live' => true]]]);
        $client = $ai->client();
        $client->complete(new AiRequest('x', 'a long prompt'));

        $inner = (new \ReflectionProperty(\Preflow\Ai\LoggedAiClient::class, 'inner'))->getValue($client);
        $this->assertInstanceOf(FakeAiClient::class, $inner);
        $this->assertSame([], $inner->requests());
    }

    public function test_an_availability_check_that_throws_is_not_available_with_its_reason(): void
    {
        $ai = $this->ai(['provider' => ThrowingFactory::class]);

        $this->assertFalse($ai->enabled());
        $status = $ai->status();
        $this->assertFalse($status->available);
        $this->assertStringContainsString('connection refused', (string) $status->reason);
    }

    public function test_status_available_defaults_to_enabled(): void
    {
        $this->assertTrue((new \Preflow\Ai\AiStatus(true, 'claude', 'm', true))->available);
        $this->assertFalse((new \Preflow\Ai\AiStatus(false, 'claude', 'm', false))->available);
    }
}

/** A factory whose probe of its server fails. */
final class ThrowingFactory implements AiClientFactory
{
    public function available(#[\SensitiveParameter] array $options): bool
    {
        throw new \RuntimeException('connection refused');
    }

    public function create(#[\SensitiveParameter] array $options): AiClient
    {
        throw new AiUnavailable('never');
    }
}

/** A keyless local model server, the AI providers guide's case. */
final class LocalModelFactory implements AiClientFactory
{
    /** @var array<string, mixed> */
    public static array $lastOptions = [];

    public function available(array $options): bool
    {
        return is_string($options['url'] ?? null) && $options['url'] !== '';
    }

    public function create(array $options): AiClient
    {
        self::$lastOptions = $options;
        if (($options['broken'] ?? false) === true) {
            throw new AiUnavailable('The local server is not configured.');
        }

        return new FakeAiClient([FakeAiClient::json(['ok' => true])], (string) $options['model']);
    }
}
