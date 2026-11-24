<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.86: components 0.86.0 (ComponentRenderer::enableState() and carryStateWith(), StateStore and
 * SignedStateStore, which the component boot wires for #[State]; an older components would fail at every boot).
 * Since 0.83: i18n 0.83.0 (LocaleMiddleware without the translator it no longer used, which the locale boot builds).
 * Since 0.81: data 0.81.0 (incrementIn()'s accounting flag, which Throttle::hit() passes for the per-person AI limits);
 * view 0.81.0 (PageRenderer and GeoMiddleware take the app's render closure first, without AssetCollector or
 * RenderScopes; the frame-bound collectors: AssetCollector::markBooted(), which a render inside another starts from,
 * NonceGenerator::adoptInFrame() and share(), SeoContext::siteDefaults() and startWith(), which verify's second render
 * starts from, GeoCapture and GeoCollector::captureInto(), which GEO hands each page it renders); auth 0.81.0
 * (AuthExtensionProvider takes a session closure, so flash() and csrf_token() read the current render's session;
 * an older auth would fail with a TypeError at every boot); i18n 0.81.0 (the translator reads the locale and its
 * overrides from the context, LocaleMiddleware narrows the frame to the locale and the URL's prefix: internal renders
 * no longer set the translator aside, and the page cache key, component URLs and the redirect check read the prefix
 * from the context; with an older i18n, an internal render from an admin request would use the admin's texts);
 * validation 0.81.0 (ValidatorFactory takes contextTranslator:, which Application passes for the admin's forms);
 * notify 0.81.0 (Deferred\DeferredQueue and Mail\ComposeScope, the seams bootNotify binds core's bridges to: the
 * Notifier's queue joins the render's outbox, so internal renders no longer drop its mail by hand; an older notify
 * would fail at every boot with config/notify.php).
 * Since 0.78.2: routing 0.78.2 (Redirect\RedirectResolver, which the clean-path one hop asks).
 * Since 0.78: data 0.78 (TypeFieldDefinition's exact, which Throttle gives its purpose field); i18n 0.78
 * (LocaleMiddleware takes urls:, cookieName:, pageExists: and nonPublic:, the bare-path redirect and the
 * language switch); view 0.78 (SeoMiddleware takes localeUrls:, the home of a prefixed default locale);
 * routing 0.78 (CanonicalPathMiddleware, which config/routing.php's canonical_paths turns on; RoutingRegistration,
 * which adds routing's Redirect\PageExists to core's page lookup sources, PageLookups).
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
            'preflow/auth' => '<0.81.0',
            'preflow/components' => '<0.86.0',
            'preflow/data' => '<0.84.0',
            'preflow/htmx' => '<0.65',
            'preflow/i18n' => '<0.83.0',
            'preflow/routing' => '<0.78.2',
            'preflow/view' => '<0.81.0',
            'preflow/twig' => '<0.60',
            'preflow/blade' => '<0.60',
            'preflow/folio' => '<0.67',
            'preflow/validation' => '<0.81.0',
            'preflow/notify' => '<0.81.0',
        ], $composer['conflict'] ?? null);
    }
}
