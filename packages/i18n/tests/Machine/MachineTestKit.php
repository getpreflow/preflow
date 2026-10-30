<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use Preflow\Ai\Ai;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiResponse;
use Preflow\Ai\Settings\AiSettingsStore;
use Preflow\Ai\StopReason;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Ai\TokenUsage;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageEntry;
use Preflow\Ai\Usage\UsageLog;
use Preflow\I18n\Machine\TranslationSettings;

/** Builds an enabled Ai around a FakeAiClient, and reads the items back out of a request. */
trait MachineTestKit
{
    /** @param array<string,mixed> $extra merged over the config */
    private function ai(FakeAiClient $fake, array $extra = [], bool $enabled = true): Ai
    {
        $config = array_replace([
            'enabled' => $enabled,
            'provider' => 'claude',
            'models' => ['claude' => 'claude-sonnet-5-5'],
            'keys' => ['claude' => 'ANTHROPIC_API_KEY'],
        ], $extra);

        return new Ai(
            $config,
            new class implements UsageLog {
                public function record(UsageEntry $e): void {}

                public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
                {
                    return [];
                }
            },
            Pricing::withOverrides([]),
            static fn (string $name): ?string => 'test-key',
            static fn (string $provider, string $model, string $key): FakeAiClient => $fake,
        );
    }

    /** @param array<string,mixed> $aiConfig */
    private function settings(array $aiConfig = []): TranslationSettings
    {
        return new TranslationSettings($aiConfig, new class implements AiSettingsStore {
            public function get(string $section): array
            {
                return [];
            }

            public function put(string $section, array $data): void {}
        });
    }

    /** @return list<array<string,mixed>> the items JSON, which is the prompt's last line */
    private static function items(AiRequest $request): array
    {
        $newline = strrpos($request->prompt, "\n");

        return json_decode(substr($request->prompt, $newline === false ? 0 : $newline + 1), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Answers every item with "DE:" + its text. */
    private static function echo(): \Closure
    {
        return static fn (AiRequest $r): AiResponse => FakeAiClient::json(array_map(
            static fn (array $i): array => ['n' => $i['n'], 'text' => 'DE:' . $i['text']],
            self::items($r),
        ));
    }

    private static function stopped(StopReason $stop, ?string $detail = null): AiResponse
    {
        return new AiResponse(null, $stop, new TokenUsage(), 'fake', 'fake-model', $detail);
    }
}
