<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.58 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing testing with a release that lacks what it relies on: core 0.58 (reads
 * DATA_CACHE_PATH, which TestDataPaths sets so per-test JSON caches stay under the test's temp
 * root) and data 0.25.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_relies_on(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['preflow/core' => '<0.58', 'preflow/data' => '<0.25'], $composer['conflict'] ?? null);
        $this->assertSame('^0.58 || @dev', $composer['require']['preflow/core'] ?? null);
    }
}
