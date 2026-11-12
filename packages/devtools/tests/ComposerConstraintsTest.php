<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.54 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing devtools with a core release that lacks what it calls: core 0.70 (`cache:clear` follows a renamed cache key through CacheRegistry::renamedTo(); since 0.60
 * auth 0.62 (`auth:token` calls AuthManager::tokenGuardName() and TokenGuard::provider()) and data 0.67 (`make:model --from-type` builds on ModelSchema and TypeRegistry's class types; 0.67 dropped matrixable and views from TypeDefinition) (the page cache's Refresher for `pages:work` and `pages:refresh`; since 0.59 ManifestBuilder for `optimize`; 0.54's InternalRender, honoured by DebugToolbarMiddleware).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_core_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.70 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame('^0.67 || @dev', $composer['require']['preflow/data'] ?? null);
        $this->assertSame(['preflow/auth' => '<0.62', 'preflow/core' => '<0.70', 'preflow/data' => '<0.67'], $composer['conflict'] ?? null);
    }
}
