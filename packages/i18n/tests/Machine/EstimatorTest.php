<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Ai\TokenUsage;
use Preflow\Ai\Usage\Pricing;
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\TranslationUnit;

/** An estimate sizes the requests a run would send, without sending any. */
final class EstimatorTest extends TestCase
{
    use MachineTestKit;

    /** @return list<TranslationUnit> */
    private function units(): array
    {
        $units = [];
        for ($i = 0; $i < 10; $i++) {
            $units[] = new TranslationUnit(id: 'u' . $i, label: 'L', source: str_repeat('a', 40), to: 'de', from: 'en');
        }
        $units[] = new TranslationUnit(id: 'fr', label: 'L', source: str_repeat('b', 40), to: 'fr', from: 'en');

        return $units;
    }

    public function test_counts_tokens_requests_and_cost(): void
    {
        $fake = new FakeAiClient([FakeAiClient::json([])]);
        $estimate = (new Estimator($this->ai($fake), 200))->estimate($this->units());

        $this->assertSame(11, $estimate->units);
        $this->assertSame(440, $estimate->characters);
        // de: 10 × 40 chars at 200 per request = 2 requests; fr: 1.
        $this->assertSame(3, $estimate->requests);
        $this->assertSame((int) ceil(1.3 * 110), $estimate->outputTokens);
        // Each request: the source tokens plus the system prompt, JSON wrapping and 200 overhead.
        $this->assertGreaterThan(110 + 3 * 200, $estimate->inputTokens);
        $this->assertLessThan(110 + 3 * 200 + 3 * 1000, $estimate->inputTokens);
        $this->assertEqualsWithDelta(
            Pricing::withOverrides([])->cost('claude-sonnet-5-5', new TokenUsage($estimate->inputTokens, $estimate->outputTokens)),
            $estimate->costUsd,
            1e-12,
        );
        $this->assertSame([], $fake->requests(), 'estimating sends nothing');
    }

    public function test_unknown_model_cost_null(): void
    {
        $ai = $this->ai(new FakeAiClient([FakeAiClient::json([])]), ['models' => ['claude' => 'claude-unknown']]);
        $estimate = (new Estimator($ai))->estimate($this->units());

        $this->assertNull($estimate->costUsd);
        $this->assertSame(2, $estimate->requests);
        $this->assertGreaterThan(0, $estimate->inputTokens);
    }
}
