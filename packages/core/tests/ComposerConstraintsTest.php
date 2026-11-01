<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Core requires none of auth, data, htmx, i18n, view, twig or blade, but boots them when they are installed,
 * so only "conflict" keeps Composer from pairing core with a release that lacks the APIs it calls: auth 0.38
 * (bootAuth binds Preflow\Auth\Record\RecordUserProvider::factory() for record users from
 * config/auth.php), data 0.28 (Application passes cacheDir: to JsonFileDriver), htmx 0.46
 * (HtmxExtensionProvider takes pathPrefix:), i18n 0.46 (LocaleMiddleware::urlPrefix()), view 0.49
 * (the Geo classes: GeoCollector, GeoMiddleware, RenderScopes, GeoEntries) and twig and blade 0.49
 * (TwigEngine and BladeEngine take geoCollector:).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([
            'preflow/auth' => '<0.38',
            'preflow/data' => '<0.28',
            'preflow/htmx' => '<0.46',
            'preflow/i18n' => '<0.46',
            'preflow/view' => '<0.49',
            'preflow/twig' => '<0.49',
            'preflow/blade' => '<0.49',
        ], $composer['conflict'] ?? null);
    }
}
