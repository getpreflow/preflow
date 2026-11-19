<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.81: core 0.81 (the render context: ContextFactory builds a RenderContext on a FrozenClock,
 * RenderIsolationContract runs renders in Contexts frames and compares RenderResults).
 * Since 0.78: data 0.78 (the capability interfaces StorageDriverContract tests: AtomicCounters, CounterReset, RankedSearch, RunsSql,
 * Transactions; TextMatch, which the text-rule scenarios compare with).
 * Since 0.77: data 0.77 (ConditionalUpdates, which StorageDriverContract tests, and the Unicode LIKE behaviour it documents).
 *
 * "^0.58 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing testing with a release that lacks what it relies on: core 0.60
 * (Application::setPageCacheVerify(), with which WebApplication verifies every stored page; since 0.58 it reads
 * DATA_CACHE_PATH, which TestDataPaths sets so per-test JSON caches stay under the test's temp
 * root), data 0.25 and htmx 0.65 (HypermediaDriver::fragmentTarget() and applyEffects(), which
 * HypermediaDriverContract tests).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_relies_on(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['preflow/core' => '<0.81.0', 'preflow/data' => '<0.79.0', 'preflow/htmx' => '<0.65'], $composer['conflict'] ?? null);
        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/core'] ?? null);
    }
}
