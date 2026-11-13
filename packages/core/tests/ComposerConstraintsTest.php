<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.74: view 0.74 (Audits, which bootAudit binds) and notify 0.74 (Notifier::discardingQueued(),
 * which every internal render runs in, so mail a render queues is dropped; with an older notify it would be sent).
 *
 * Since 0.67: i18n 0.67 and folio 0.67 (core keeps composer extra whole and reads the runtime files packages declare, extra.preflow.runtime; an older i18n or Folio would lose its translations, extensions, blocks and field types).
 *
 * Since 0.66: auth 0.66 (LazyGuard, LazyPasswordHasher: guards of the app's own classes are built on first use,
 * the hasher is a container binding) and view 0.66 (ErrorPageRenderer, the app's error pages).
 *
 * Since 0.66.1: data 0.66.1 (Dialect::insertOrUpdateSql(), which the throttle's hit asks for).
 *
 * Since 0.65: data 0.65 (DataManager takes a closure for a class-named storage driver), htmx 0.65
 * (LazyHypermediaDriver; HypermediaDriver extends core's HypermediaProtocol, which HypermediaProtocols
 * holds) and view 0.65 (LazyTemplateEngine, TemplateEngineContext, NonceGenerator::adopt(), the
 * hypermedia: parameters of GeoMiddleware, SeoMiddleware and PageResponder).
 *
 * Core requires none of auth, components, data, htmx, i18n, routing, view, twig or blade, but boots them when they are installed,
 * so only "conflict" keeps Composer from pairing core with a release that lacks the APIs it calls: auth 0.62
 * (TokenAuthMiddleware, which bootAuth registers; 0.60: SessionGuard takes marker:, the signed-in marker the page cache bypasses on; since 0.38 bootAuth binds Preflow\Auth\Record\RecordUserProvider::factory() for record users from
 * config/auth.php), components 0.60
 * (ComponentRenderer::enableLive() and enableFreshness(), ErrorBoundary takes onError:), data 0.61 (ModelClasses and the TypeRegistry's class argument, which Application boots; since 0.60 DataManager::observe()
 * and reportedStorage() for the page cache; since 0.59 Application passes cacheDir: to JsonFileDriver; since 0.59 the drivers take a connector
 * closure, the lazy connection), htmx 0.60
 * (the page cache renders a page twice in one request and rebuilds after the response: HtmxAssets
 * adds htmx to every render and ComponentToken issues a request's tokens at one time; before, a
 * second render had no htmx and could differ in its tokens; since 0.46 HtmxExtensionProvider takes pathPrefix:), i18n 0.46 (LocaleMiddleware::urlPrefix()), routing 0.62 (Router throws MethodNotAllowedHttpException; 0.59: Router takes store:, the boot manifest's route sets), view 0.63
 * (PageResponder::requestVariable(), which the file-page renderer calls and Application binds PageResponder for; since 0.60 SeoContext::useDefaults(), SeoMiddleware::pageLocale(), SeoSite::defaultsFor(); the Geo classes: GeoCollector, GeoMiddleware, RenderScopes, GeoEntries; the Seo classes: SeoConfig,
 * SeoContext, SeoSite, SchemaRegistry, SeoMiddleware; the Audit classes: AuditRegistry, PageRenderer
 * and AssetCollector::restoring(); since 0.58 AssetCollector takes cachePath:, which Application passes) and twig and blade 0.60 (TwigEngine and BladeEngine
 * take liveParts: and pageCache:; since 0.50 geoCollector: and seoContext:).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([
            'preflow/auth' => '<0.66',
            'preflow/components' => '<0.60',
            'preflow/data' => '<0.66.1',
            'preflow/htmx' => '<0.65',
            'preflow/i18n' => '<0.67',
            'preflow/routing' => '<0.62',
            'preflow/view' => '<0.74',
            'preflow/twig' => '<0.60',
            'preflow/blade' => '<0.60',
            'preflow/folio' => '<0.67',
            'preflow/notify' => '<0.74',
        ], $composer['conflict'] ?? null);
    }
}
