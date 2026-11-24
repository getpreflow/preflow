<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.81.0 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing htmx with a core release that lacks what it calls.
 * Since 0.86: core 0.86.0 (Http\Hypermedia\DrivesComponentActions, ActionCall and ActionOptions, which HtmxDriver
 * implements and the hd.post/get/on short form builds); components 0.86.0 (#[State] and the state stores, hasState(),
 * stateFor(), restore(), renderAfterAction(), parentOf(), with which the endpoint carries a component's state);
 * view 0.86.0 (SafeHtml, which the hd helpers return, and Regions, which hd.region() names); twig and blade 0.86.0
 * (they print SafeHtml unescaped; with an older engine the short form's attributes would be escaped).
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

        $this->assertSame('^0.86.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame('^0.86.0 || @dev', $composer['require']['preflow/components'] ?? null);
        $this->assertSame([
            'preflow/core' => '<0.86.0',
            'preflow/view' => '<0.86.0',
            'preflow/components' => '<0.86.0',
            'preflow/twig' => '<0.86.0',
            'preflow/blade' => '<0.86.0',
        ], $composer['conflict'] ?? null);
    }
}
