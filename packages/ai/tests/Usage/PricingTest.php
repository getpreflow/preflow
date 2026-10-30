<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\TokenUsage;
use Preflow\Ai\Usage\Pricing;

final class PricingTest extends TestCase
{
    public function test_cost_for_known_model(): void
    {
        $pricing = Pricing::withOverrides([]);

        $this->assertSame(12.0, $pricing->cost('claude-sonnet-5-5', new TokenUsage(1_000_000, 1_000_000)));
        $this->assertTrue($pricing->has('gemini-2.5-flash'));
    }

    public function test_unknown_model_is_null(): void
    {
        $pricing = Pricing::withOverrides([]);

        $this->assertNull($pricing->cost('mystery-1', new TokenUsage(1000, 1000)));
        $this->assertFalse($pricing->has('mystery-1'));
    }

    public function test_cached_input_at_ten_percent(): void
    {
        $pricing = new Pricing(['m' => [2.0, 10.0]]);

        // 1M prompt tokens, all cached: 10% of the 2.00 input price.
        $this->assertEqualsWithDelta(0.2, $pricing->cost('m', new TokenUsage(1_000_000, 0, 1_000_000)), 1e-9);
        // Half cached: 0.5 * 2.00 + 0.5 * 0.20.
        $this->assertEqualsWithDelta(1.1, $pricing->cost('m', new TokenUsage(1_000_000, 0, 500_000)), 1e-9);
    }

    public function test_overrides_merge_over_defaults(): void
    {
        $pricing = Pricing::withOverrides(['claude-sonnet-5-5' => [1.0, 1.0], 'custom' => [3.0, 4.0]]);

        $this->assertSame(2.0, $pricing->cost('claude-sonnet-5-5', new TokenUsage(1_000_000, 1_000_000)));
        $this->assertSame(7.0, $pricing->cost('custom', new TokenUsage(1_000_000, 1_000_000)));
        $this->assertTrue($pricing->has('claude-opus-5-5'));
    }
}
