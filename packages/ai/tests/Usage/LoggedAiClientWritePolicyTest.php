<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequest;
use Preflow\Ai\LoggedAiClient;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageEntry;
use Preflow\Ai\Usage\UsageLog;
use Preflow\Core\Context\Contexts;
use Preflow\Testing\ContextFactory;

/** The usage entry is accounting: written under every write policy, never reported as skipped. */
final class LoggedAiClientWritePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    public function test_the_usage_entry_is_written_under_every_policy(): void
    {
        $log = new class implements UsageLog {
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
        $client = new LoggedAiClient(new FakeAiClient([FakeAiClient::json(['ok' => true])]), $log, Pricing::withOverrides([]));

        foreach (['skipped' => ContextFactory::forTest()->skippingWrites(), 'refused' => ContextFactory::forTest()->refusingWrites()] as $case => $ctx) {
            $frame = null;
            $response = Contexts::run($ctx, function () use ($client, &$frame) {
                $frame = Contexts::frame();

                return $client->complete(new AiRequest('draft', 'a prompt'));
            });
            $this->assertSame('{"ok":true}', $response->text, "{$case}: the answer comes back");
            $this->assertSame([], $frame->skippedWrites(), "{$case}: accounting is no skipped write");
        }
        $this->assertCount(2, $log->entries, 'the spending is recorded in a preview too');

        $client->complete(new AiRequest('draft', 'a prompt'));
        $this->assertCount(3, $log->entries, 'and outside a render');
    }
}
