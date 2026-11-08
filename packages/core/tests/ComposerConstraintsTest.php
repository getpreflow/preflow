<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Core requires none of auth, data, htmx, i18n, routing, view, twig or blade, but boots them when they are installed,
 * so only "conflict" keeps Composer from pairing core with a release that lacks the APIs it calls: auth 0.38
 * (bootAuth binds Preflow\Auth\Record\RecordUserProvider::factory() for record users from
 * config/auth.php), data 0.59 (Application passes cacheDir: to JsonFileDriver; since 0.59 the drivers take a connector
 * closure, the lazy connection), htmx 0.46
 * (HtmxExtensionProvider takes pathPrefix:), i18n 0.46 (LocaleMiddleware::urlPrefix()), routing 0.59 (Router takes store:, the boot manifest's route sets), view 0.58
 * (the Geo classes: GeoCollector, GeoMiddleware, RenderScopes, GeoEntries; the Seo classes: SeoConfig,
 * SeoContext, SeoSite, SchemaRegistry, SeoMiddleware; the Audit classes: AuditRegistry, PageRenderer
 * and AssetCollector::restoring(); since 0.58 AssetCollector takes cachePath:, which Application passes) and twig and blade 0.50 (TwigEngine and BladeEngine
 * take geoCollector: and seoContext:).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([
            'preflow/auth' => '<0.38',
            'preflow/data' => '<0.59',
            'preflow/htmx' => '<0.46',
            'preflow/i18n' => '<0.46',
            'preflow/routing' => '<0.59',
            'preflow/view' => '<0.58',
            'preflow/twig' => '<0.50',
            'preflow/blade' => '<0.50',
        ], $composer['conflict'] ?? null);
    }
}
