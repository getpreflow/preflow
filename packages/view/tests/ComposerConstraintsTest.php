<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.50 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing view with a core release that lacks what it relies on: core 0.49
 * (SessionMiddleware::WITHOUT_SESSION, which GeoMiddleware's internal renders set) and core 0.50
 * (binds SeoConfig, SeoContext, SeoSite and SchemaRegistry and runs SeoMiddleware), core 0.54
 * (InternalRender, which the SEO audit's PageRenderer marks its renders with; ErrorHandler's production
 * page for them; binds AuditRegistry and PageRenderer). The audit also relies on routing, media and
 * devtools 0.54 honouring that marker (no not-found entry or redirect hit, no image variant made, no
 * debug toolbar), so those older releases conflict too. GeoMiddleware and MarkdownConverter parse
 * pages with Dom\HTMLDocument, so view requires ext-dom.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls_and_ext_dom(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.54 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame([
            'preflow/core' => '<0.54',
            'preflow/routing' => '<0.54',
            'preflow/media' => '<0.54',
            'preflow/devtools' => '<0.54',
        ], $composer['conflict'] ?? null);
        $this->assertSame('*', $composer['require']['ext-dom'] ?? null);
    }
}
