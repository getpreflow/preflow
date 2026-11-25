<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.88: core 0.88 (ActionCall's token:/endpointUrl:, which HypermediaDriverContract passes); htmx 0.88
 * (ComponentEndpoint::TOKEN_FIELD, which WebApplication::componentAction() sends, and the endpoint that reads it).
 * Since 0.87: core 0.87 (ActionCall's carriesState: and 'blur' in ActionOptions::EVENTS, which HypermediaDriverContract
 * passes to a driver; on core 0.86 the named argument is a fatal error).
 * Since 0.86: core 0.86 (Http\Hypermedia\DrivesComponentActions, ActionCall and ActionOptions, which
 * HypermediaDriverContract tests); htmx 0.86 (ComponentToken::encodeFor() and the neutral _state/_region/_values
 * fields, with which WebApplication::componentAction() calls an action the way a page does); components 0.86
 * (StateStore, SignedStateStore and StateRefused, which StateStoreContract tests; enableState(), which TestApplication
 * turns on); view 0.86 (SafeHtml and RendersRegions, which TemplateEngineContract tests).
 * Since 0.85: core 0.85 (PageCache\Cacheability, which WebApplication::assertPageCacheable() asks).
 * Since 0.82: core 0.82 (Deprecations::expected(), with which RenderIsolationContract marks its own calls of the deprecated
 * setters).
 * Since 0.81: core 0.81 (the render context: ContextFactory builds a RenderContext on a FrozenClock,
 * RenderIsolationContract runs renders in Contexts frames and compares RenderResults); view 0.81 (NonceGenerator::fixedForTests(),
 * with which RenderIsolationContract gives every render the same nonce); auth 0.81 (AuthManager::viewerOf() and
 * defaultGuardName(), with which actingAs() builds the viewer of the request's frame).
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

        $this->assertSame(['preflow/auth' => '<0.81.0', 'preflow/core' => '<0.88.0', 'preflow/data' => '<0.89.0', 'preflow/htmx' => '<0.88.0', 'preflow/view' => '<0.86.0', 'preflow/components' => '<0.86.0'], $composer['conflict'] ?? null);
        $this->assertSame('^0.88.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame('^0.88.0 || @dev', $composer['require']['preflow/htmx'] ?? null);
        foreach (['view', 'components'] as $package) {
            $this->assertSame('^0.86.0 || @dev', $composer['require']['preflow/' . $package] ?? null, $package);
        }
    }
}
