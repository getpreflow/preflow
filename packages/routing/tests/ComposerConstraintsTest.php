<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.81: core 0.81.0 (Context\Writes: the redirect hit counter and the not-found log ask the render's write policy).
 * Since 0.79.0: core 0.79.0 (Routing\AnswersPages, which Router implements). Core 0.78.2 (PageLookups::addAnswer(), LocaleUrls::$homeSlash and isHome()).
 * "^0.78 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing routing with a core release that lacks what it calls: core 0.78
 * (Routing\PageExists, which Router and Redirect\PageExists implement; Routing\PageLookups, to which RoutingRegistration
 * adds Redirect\PageExists), core 0.72 (ConfiguredMiddleware, which MiddlewareWith implements and the kernel builds; since 0.62
 * MethodNotAllowedHttpException, thrown by Router).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame(['preflow/core' => '<0.81.0'], $composer['conflict'] ?? null);
    }
}
