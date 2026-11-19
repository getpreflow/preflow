<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.81.0 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing ai with a core release that lacks what it calls.
 * Since 0.81: core 0.81.0 (Context\Writes: the usage log and the per-person limits are
 * accounting writes, made under every write policy; Throttle::hit() takes the accounting flag).
 * Before: core 0.78 (Throttle::hit() returning the count, which AiLimits reads).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame(['preflow/core' => '<0.81.0'], $composer['conflict'] ?? null);
    }
}
