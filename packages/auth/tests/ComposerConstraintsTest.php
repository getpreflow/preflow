<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.60 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing auth with a core release that lacks what it calls: core 0.60 (CookieQueue::has(), with which SessionGuard queues the healed signed-in marker once).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.60 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame(['preflow/core' => '<0.60'], $composer['conflict'] ?? null);
    }
}
