<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.81.0 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing twig with a core release that lacks what it calls: since 0.81 core 0.81.0
 * (Context\Preview\PreviewUrls, which hd's component URLs go through so a preview token reaches
 * the follow-up requests of a previewed page); before, core 0.60 (the page cache: LiveParts, PageCache and Freshness behind {% live %}, {% freshness %} and {% expires %}), and from pairing it with a view release before 0.72, which lacks SeoContext::description() behind seo_description().
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

    /** {% live %} calls Parser::parseExpression() (Twig 3.21) and builds a Twig\Node\EmptyNode (3.13). */
    public function test_requires_the_twig_version_the_live_tag_uses(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^3.21', $composer['require']['twig/twig'] ?? null);
    }
}
