<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.69 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing blocks with a release that lacks the APIs it calls: core 0.69 (boot manifest
 * catalogs, installedPackages(), CacheRegistry, PageCache::refuse()), components 0.69 (root attributes,
 * ComponentMap), data 0.69 (LocaleValue::resolveTree(), TypeFieldDefinition), view 0.69 (template functions).
 * folio 0.70 (before it Folio ships its own block classes, registry and renderer, which clash with this package's; it is not
 * required: it carries the blocks field and its editor, and registers this package itself since 0.70).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([
            'preflow/components' => '<0.69',
            'preflow/core' => '<0.69',
            'preflow/data' => '<0.69',
            'preflow/folio' => '<0.70',
            'preflow/view' => '<0.69',
        ], $composer['conflict'] ?? null);
    }
}
