<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.85: core 0.85 (PageCache\Cacheability, which `pages:why` asks).
 * Since 0.82: core 0.82 (Http\ContentSecurityPolicy, from which the toolbar takes the page's nonces; RequestLimits).
 * Since 0.81: core 0.81 (Contexts, RenderContext, Site, SystemClock and CookieChannel: every command runs in a console frame).
 * Since 0.80: data 0.80 (Seed, the attribute db:seed reads for debug-only seeders).
 * Since 0.78: data 0.78 (PdoConnection, which migrate asks for; the text-rule conversion and the recorded column intent migrate runs);
 * core 0.78 (Throttle::prune() deleting ended rows, which throttle:prune runs).
 * Since 0.77.1: data 0.77.1 (MigrationRegistry::steps(sql:), which migrate calls on a JSON-only site).
 * Since 0.77: core 0.77 (Activity\ActivityLog::prune(), which the activity:prune command calls).
 *
 * "^0.54 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing devtools with a core release that lacks what it calls: core 0.70 (`cache:clear` follows a renamed cache key through CacheRegistry::renamedTo(); since 0.60
 * auth 0.62 (`auth:token` calls AuthManager::tokenGuardName() and TokenGuard::provider()) and data 0.75 (`make:model --from-type` writes #[Relation(metaField: …)], data 0.75's; since 0.72 #[Index]; since 0.67 it builds on ModelSchema and TypeRegistry's class types; 0.67 dropped matrixable and views from TypeDefinition) (the page cache's Refresher for `pages:work` and `pages:refresh`; since 0.59 ManifestBuilder for `optimize`; 0.54's InternalRender, honoured by DebugToolbarMiddleware).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.85.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame('^0.89.0 || @dev', $composer['require']['preflow/data'] ?? null);
        $this->assertSame(['preflow/auth' => '<0.62', 'preflow/core' => '<0.85.0', 'preflow/data' => '<0.89.0'], $composer['conflict'] ?? null);
    }
}
