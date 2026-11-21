<?php

declare(strict_types=1);

namespace Preflow\Roles\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.81.0 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing roles with a release that lacks the APIs it calls: auth 0.81.0
 * (Access\AllowsRoles, which ConfigRoleResolver implements for role views; since 0.65 ListsRoles).
 * Core stays "^0.20 || @dev": roles names only core's Application, Container and ServiceProvider,
 * and auth 0.81.0 requires and conflicts core 0.81.0, so Composer cannot pair roles 0.81 with an
 * older core anyway.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['preflow/auth' => '<0.81.0'], $composer['conflict'] ?? null);
        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/auth'] ?? null);
    }
}
