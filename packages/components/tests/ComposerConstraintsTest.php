<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.60 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing components with a core release that lacks what it calls: core 0.81.0 (Context\WritesRefused,
 * which every render method rethrows so a refused write answers 403); core 0.60 (the page cache: LiveParts, PageCache and Freshness behind #[Live] and #[Freshness]).
 * Likewise view: since 0.86 view 0.86.0 (Regions and RendersRegions, with which renderAfterAction() renders only the
 * region an action targets); before, view 0.81.0 (GeoCollector::component(), which renders a component in a GEO block of its own).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame(['preflow/core' => '<0.81.0', 'preflow/view' => '<0.86.0'], $composer['conflict'] ?? null);
    }

    public function test_requires_view_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.86.0 || @dev', $composer['require']['preflow/view'] ?? null);
    }
}
