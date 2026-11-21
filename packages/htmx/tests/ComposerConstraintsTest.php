<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.81.0 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing htmx with a core release that lacks what it calls.
 * Since 0.81: core 0.81.0 (Context\WritesRefused, which the component endpoint rethrows so a
 * refused write in an action answers 403; Context\Preview\PreviewUrls and
 * Http\Hypermedia\CarriesRequestHeaders, which carry a preview token to a previewed page's follow-ups). Before: core 0.65. Likewise view 0.81.0 (AssetCollector::NONCE,
 * which the htmx config tag holds so each render prints its own nonce; frame-bound assets).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame(['preflow/core' => '<0.81.0', 'preflow/view' => '<0.81.0'], $composer['conflict'] ?? null);
    }
}
