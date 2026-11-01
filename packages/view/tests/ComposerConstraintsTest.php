<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.49 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing view with a core release that lacks what it calls: core 0.49
 * (SessionMiddleware::WITHOUT_SESSION, which GeoMiddleware's internal renders set). GeoMiddleware
 * and MarkdownConverter parse pages with Dom\HTMLDocument, so view requires ext-dom.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls_and_ext_dom(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.49 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame(['preflow/core' => '<0.49'], $composer['conflict'] ?? null);
        $this->assertSame('*', $composer['require']['ext-dom'] ?? null);
    }
}
