<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.60 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing auth with a core release that lacks what it calls: core 0.60 (CookieQueue::has(), with which SessionGuard queues the healed signed-in marker once).
 * Since 0.81: core 0.81.0 (the render context: Identity, which Authenticatable extends, Contexts and Viewer, which AuthManager
 * and the identity middleware read and narrow, RoleView and SyntheticIdentity behind SyntheticUser, Deprecations for the shims).
 * Since 0.82: core 0.82.0 (CookieQueue::reachesResponse(), which SessionGuard asks before ResolveUserMiddleware sets its cookies).
 * Since 0.81: data 0.81.0 (DataManager::updateIf() and deleteIf(), the conditional writes behind Tokens::consume() and the
 * remember-me rotation, which follow the write policy the same on every storage).
 * Since 0.78: data 0.78 (TypeFieldDefinition's exact, which RememberMe and Tokens give their ids, hashes and purposes).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.82.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/data'] ?? null);
        $this->assertSame(['preflow/core' => '<0.82.0', 'preflow/data' => '<0.81.0'], $composer['conflict'] ?? null);
    }
}
