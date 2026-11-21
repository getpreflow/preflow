<?php

declare(strict_types=1);

namespace Preflow\Blade\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.81.0 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing blade with a core release that lacks what it relies on: since 0.81 core
 * 0.81.0, the release line every package that names core is on (blade calls no class that is new in
 * 0.81, but its @live parts and its asset and GEO directives run inside 0.81's per-render frames,
 * and it is tested only against them); before, core 0.60 (the page cache: LiveParts, PageCache and Freshness behind @live, @freshness and @expires), and from pairing it with a view release before 0.72, which lacks SeoContext::description() behind seo_description().
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame(['preflow/core' => '<0.81.0', 'preflow/view' => '<0.72'], $composer['conflict'] ?? null);
        $this->assertSame('^0.72 || @dev', $composer['require']['preflow/view'] ?? null);
    }
}
