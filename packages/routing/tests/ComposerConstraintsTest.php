<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.62 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing routing with a core release that lacks what it calls: core 0.62
 * (MethodNotAllowedHttpException, thrown by Router).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.62 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame(['preflow/core' => '<0.62'], $composer['conflict'] ?? null);
    }
}
