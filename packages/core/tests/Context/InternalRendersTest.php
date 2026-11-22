<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthManager;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\ReadMode;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\I18n\Translator;
use Preflow\View\AssetCollector;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Testing\ChecksFrameBalance;

/**
 * The SEO audit and page cache rebuilds render through Application::render() in an internal
 * context of their own: anonymous, published, in the site's locale, with the visitor's PHP
 * superglobals and session out of reach, and nothing of the request around them (its assets,
 * its debug data) taken or lost.
 */
final class InternalRendersTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        InternalRoutes::$routes = [];
        InternalRoutes::$seen = [];
        $this->dir = sys_get_temp_dir() . '/internal_renders_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        @mkdir($this->dir . '/templates', 0777, true);
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        InternalRoutes::$routes = [];
        InternalRoutes::$seen = [];
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function site(bool $debug = false, bool $pageCache = false): Application
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'internal', 'debug' => " . ($debug ? 1 : 0) . ", 'engine' => 'twig', 'key' => 'internal-renders-test-key-0123456789', 'url' => 'https://site.test', 'locale' => 'en'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix', 'cookie' => false];\n");
        file_put_contents($this->dir . '/config/auth.php', '<?php return ' . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users']],
            'providers' => ['users' => ['class' => ConfigUserProvider::class, 'users' => [
                ['id' => 'member', 'email' => 'member@site.test', 'password_hash' => '', 'roles' => []],
            ]]],
            // Sessions only where an admin works: a public page starts none (and may be stored).
            'session' => ['cookie' => 'internal', 'secure' => false, 'paths' => ['/admin']],
        ], true) . ";\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(InternalRoutes::class, true) . "];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(InternalRendersProvider::class, true) . "];\n");
        if ($pageCache) {
            file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true, 'verify' => 'off'];\n");
        }
        file_put_contents(
            $this->dir . '/app/pages/index.twig',
            '<html><head><title>Home</title>{{ head() }}</head><body><main><p>{{ current_locale() }}|{{ auth_check() ? \'in\' : \'out\' }}|{{ cookie_global(\'m\') }}|{{ flash(\'note\') }}|{{ old(\'name\') }}</p></main>{{ assets() }}</body></html>',
        );
        file_put_contents($this->dir . '/templates/flash.twig', '{{ flash(\'note\') }}/{{ old(\'name\') }}');

        $app = Application::create($this->dir);
        if ($pageCache) {
            $app->setRunningInConsole(false);
            $app->runAfterResponseInHandle();
        }
        $app->boot();

        return $app;
    }

    private static function main(string $body): string
    {
        return preg_match('~<main><p>(.*?)</p></main>~s', $body, $m) === 1 ? $m[1] : $body;
    }

    #[RunInSeparateProcess]
    public function test_audit_render_is_anonymous_published_and_in_the_site_locale(): void
    {
        $app = $this->site();
        InternalRoutes::$routes['/admin/step'] = static function (ServerRequestInterface $r, Container $c): ResponseInterface {
            // An admin request: signed in, its interface in German.
            $auth = $c->get(AuthManager::class);
            $auth->setUser((new ConfigUserProvider([['id' => 'member', 'email' => 'member@site.test', 'password_hash' => '']]))->findById('member@site.test'), 'session');
            $translator = $c->get(Translator::class);
            $translator->setLocale('de');

            $page = $c->get(PageRenderer::class)->render('/');

            return new Response(200, [], self::main($page->body) . ' after: ' . $translator->getLocale() . ', ' . ($auth->user() !== null ? 'in' : 'out'));
        };

        $body = (string) $app->handle(new ServerRequest('GET', 'https://site.test/admin/step'))->getBody();

        $this->assertSame('en|out||| after: de, in', $body, 'the page as an anonymous visitor gets it; the admin request as it was afterwards');
        $seen = InternalRoutes::$seen['/'][0] ?? null;
        $this->assertNotNull($seen, 'the page was rendered');
        $this->assertSame([Purpose::Audit, ReadMode::published()->key(), true, 2], [$seen['purpose'], $seen['reads'], $seen['anonymous'], $seen['depth']]);
    }

    #[RunInSeparateProcess]
    public function test_rebuild_does_not_inherit_a_fragments_assets(): void
    {
        $app = $this->site(pageCache: true);
        $this->assertSame('miss', $app->handle(new ServerRequest('GET', 'https://site.test/'))->getHeaderLine('X-Page-Cache'));
        // A fragment request adds its CSS and changes what every page shows: '/' is rebuilt behind its response.
        InternalRoutes::$routes['/--component/frag'] = static function (ServerRequestInterface $r, Container $c): ResponseInterface {
            $c->get(AssetCollector::class)->addCss('.x-fragment { color: red; }', 'x-fragment');
            $c->get(PageCache::class)->changed('*');

            return new Response(200, ['Content-Type' => 'text/html'], '<p>fragment</p>');
        };

        $app->handle(new ServerRequest('GET', 'https://site.test/--component/frag'));

        $stored = $this->stored($app, '/');
        $this->assertNotNull($stored, 'rebuilt behind the fragment\'s response');
        $this->assertStringContainsString('en|out|||', $stored->body);
        $this->assertStringNotContainsString('x-fragment', $stored->body, 'the rebuild starts from its own assets, not the fragment\'s');
    }

    /** The real path: a request queues rebuilds; after its response they run as Rebuild renders nested in its frame. */
    #[RunInSeparateProcess]
    public function test_scheduled_rebuilds_run_after_the_response_as_rebuilds_inside_the_request_frame(): void
    {
        $app = $this->site(pageCache: true);
        $app->handle(new ServerRequest('GET', 'https://site.test/'));
        InternalRoutes::$seen = [];
        InternalRoutes::$routes['/change'] = static function (ServerRequestInterface $r, Container $c): ResponseInterface {
            $c->get(PageCache::class)->changed('*');

            return new Response(200, [], 'changed');
        };

        $app->handle(new ServerRequest('GET', 'https://site.test/change'));

        $rebuild = InternalRoutes::$seen['/'][0] ?? null;
        $this->assertNotNull($rebuild, 'the page was rebuilt after the response');
        $this->assertSame(Purpose::Rebuild, $rebuild['purpose']);
        $this->assertSame(2, $rebuild['depth'], 'inside the request\'s frame, in a frame of its own');
        $this->assertSame('/', $rebuild['path']);
        $this->assertTrue($rebuild['anonymous']);
        $this->assertNotNull($this->stored($app, '/'), 'and stored');
        $this->assertSame(0, Contexts::depth());
    }

    /**
     * A capturing or internal render that never used its outbox closes without making one, and
     * makes none on the frame around it either: its result's captured items are simply empty.
     */
    #[RunInSeparateProcess]
    public function test_a_render_that_never_used_its_outbox_makes_none(): void
    {
        $app = $this->site();
        $frames = [];
        Contexts::onEnter(static function (RenderContext $ctx, \Preflow\Core\Context\RenderFrame $frame) use (&$frames): ?\Closure {
            $frames[] = $frame;

            return null;
        });

        $outer = null;
        $results = Contexts::run(RenderContext::console($app->site(), new SystemClock()), function () use ($app, &$outer): array {
            $outer = Contexts::frame();

            return [
                $app->render(RenderContext::internal(Purpose::Audit, $app->site(), new SystemClock(), '/'), new ServerRequest('GET', 'https://site.test/')),
                $app->render(RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/'), $app->site(), new SystemClock())->capturing(), new ServerRequest('GET', 'https://site.test/')),
            ];
        });

        foreach ($results as $result) {
            $this->assertSame(200, $result->response->getStatusCode());
            $this->assertSame([], $result->captured);
        }
        $this->assertCount(3, $frames, 'the outer frame and the two renders');
        foreach ($frames as $i => $frame) {
            $this->assertNull($frame->madeOutbox(), "frame {$i} made no outbox");
        }
        $this->assertSame($outer, $frames[0]);
    }

    #[RunInSeparateProcess]
    public function test_internal_render_sees_no_visitor_superglobals(): void
    {
        $app = $this->site();
        $_COOKIE['m'] = 'visitor';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        $page = $app->render(
            RenderContext::internal(Purpose::Audit, $app->site(), new SystemClock(), '/'),
            new ServerRequest('GET', 'https://site.test/'),
        );

        $this->assertSame('en|out|||', self::main((string) $page->response->getBody()));
        $this->assertSame('127.0.0.1', InternalRoutes::$seen['/'][0]['remote'] ?? null, 'the address PHP gives is not the visitor\'s');
        $this->assertSame('visitor', $_COOKIE['m'] ?? null, 'the superglobals are back afterwards');
        $this->assertSame('203.0.113.9', $_SERVER['REMOTE_ADDR']);
    }

    /**
     * flash() and old() read the session of the render's own request. The admin request's session
     * here does not live in $_SESSION (an array session, as a site's or a test's may be), so hiding
     * the superglobal proves nothing: before 0.81 old() read the container's session and showed the
     * admin's kept fields in the audited page.
     */
    #[RunInSeparateProcess]
    public function test_session_helpers_read_the_renders_own_session(): void
    {
        $app = $this->site();
        InternalRoutes::$routes['/admin/flash'] = function (ServerRequestInterface $r, Container $c): ResponseInterface {
            $session = new \Preflow\Testing\ArraySession();
            $session->start();
            $session->flash('note', 'kept');
            \Preflow\Core\Http\OldInput::keep($session, ['name' => 'Ada']);
            $c->instance(SessionInterface::class, $session);
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->for($r->withAttribute(SessionInterface::class, $session)));
            $engine = $c->get(TemplateEngineInterface::class);
            $before = $engine->render($this->dir . '/templates/flash.twig');

            $page = $c->get(PageRenderer::class)->render('/');

            return new Response(200, [], $before . ' / ' . self::main($page->body) . ' / ' . $engine->render($this->dir . '/templates/flash.twig'));
        };

        $body = (string) $app->handle(new ServerRequest('GET', 'https://site.test/admin/flash'))->getBody();

        $this->assertSame('kept/Ada / en|out||| / kept/Ada', $body, 'the audit\'s page has no session; the admin\'s flash and kept fields are there before and after');
    }

    #[RunInSeparateProcess]
    public function test_audit_from_an_admin_request_keeps_the_admin_debug_collector(): void
    {
        $app = $this->site(debug: true);
        InternalRoutes::$routes['/admin/debug'] = static function (ServerRequestInterface $r, Container $c): ResponseInterface {
            $collector = $c->get(DebugCollector::class);
            $collector->logQuery('SELECT outer', [], 1.0);
            $c->get(PageRenderer::class)->render('/');
            $c->get(PageRenderer::class)->render('/');

            return new Response(200, [], implode(',', array_column($collector->getQueries(), 'sql')));
        };

        $body = (string) $app->handle(new ServerRequest('GET', 'https://site.test/admin/debug'))->getBody();

        $this->assertSame('SELECT outer', $body, 'the admin request\'s own data survives; the audited pages\' do not pile up in it');
    }

    /** pages:work: a freshly booted app (the cron's) rebuilds a page as visitors got it, htmx included. */
    #[RunInSeparateProcess]
    public function test_a_rebuild_from_a_fresh_app_stores_the_page_a_visit_stored(): void
    {
        $app = $this->site(pageCache: true);
        $app->handle(new ServerRequest('GET', 'https://site.test/'));
        $visit = $this->stored($app, '/')?->body;
        $this->assertNotNull($visit);
        $this->assertStringContainsString('htmx-config', $visit, 'htmx (include: always) is on the visitor\'s page');

        $cron = Application::create($this->dir);
        $cron->boot();
        $refresher = $cron->container()->get(\Preflow\Core\PageCache\Refresher::class);
        $this->assertSame(1, $refresher->refreshAll());
        $this->assertSame(1, $refresher->work(50.0));

        $this->assertSame($visit, $this->stored($cron, '/')?->body, 'the rebuilt page is the visitor\'s page');
    }

    /**
     * A request printed the page (its htmx is marked printed in the process's asset registry), and
     * the next one queues a rebuild: the rebuild still prints htmx (0.80 lost it here).
     */
    #[RunInSeparateProcess]
    public function test_a_rebuild_after_a_request_that_printed_the_page_still_has_htmx(): void
    {
        $app = $this->site(pageCache: true);
        $app->handle(new ServerRequest('GET', 'https://site.test/'));
        $visit = $this->stored($app, '/')?->body;
        $this->assertStringContainsString('htmx-config', (string) $visit);
        InternalRoutes::$routes['/change'] = static function (ServerRequestInterface $r, Container $c): ResponseInterface {
            $c->get(PageCache::class)->changed('*');

            return new Response(200, [], 'changed');
        };

        $app->handle(new ServerRequest('GET', 'https://site.test/change'));

        $rebuilt = $this->stored($app, '/')?->body;
        $this->assertNotNull($rebuilt);
        $this->assertSame($visit, $rebuilt, 'nothing the earlier request printed is marked printed in the rebuild');
    }

    /** The CLI audit (no request around it) checks the page visitors get. */
    #[RunInSeparateProcess]
    public function test_the_audit_checks_the_page_visitors_get(): void
    {
        $visitor = (string) $this->site()->handle(new ServerRequest('GET', 'https://site.test/'))->getBody();
        $page = $this->site()->container()->get(PageRenderer::class)->render('/');

        $strip = static fn (string $html): string => (string) preg_replace('/nonce="[^"]*"|"inline(Script|Style)Nonce":"[^"]*"/', '', $html);
        $this->assertStringContainsString('htmx-config', $page->body);
        $this->assertSame($strip($visitor), $strip($page->body));
    }

    private function stored(Application $app, string $path): ?\Preflow\Core\PageCache\StoredPage
    {
        $config = $app->container()->get(PageCacheConfig::class);
        $store = new PageStore($this->dir . '/storage/cache/pages', PageStore::releaseKey($this->dir));

        return $store->get(PageKey::fromRequest(new ServerRequest('GET', 'https://site.test' . $path), $config, ['locale' => 'en']));
    }
}

/** Routes by path, and a record of the context each page render of '/' ran under. */
final class InternalRoutes implements MiddlewareInterface
{
    /** @var array<string, \Closure(ServerRequestInterface, Container): ResponseInterface> */
    public static array $routes = [];

    /** @var array<string, list<array<string, mixed>>> */
    public static array $seen = [];

    public function __construct(private readonly Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $route = self::$routes[$path] ?? null;
        if ($route !== null) {
            return $route($request, $this->container);
        }
        if ($path === '/') {
            $ctx = Contexts::current();
            self::$seen['/'][] = [
                'purpose' => $ctx->purpose,
                'reads' => $ctx->reads->key(),
                'anonymous' => $ctx->viewer->isAnonymous(),
                'depth' => Contexts::depth(),
                'path' => $ctx->request?->path,
                'remote' => $_SERVER['REMOTE_ADDR'] ?? null,
            ];
            if ($this->container->has(DebugCollector::class)) {
                $this->container->get(DebugCollector::class)->logQuery('SELECT inner', [], 1.0);
            }
        }

        return $handler->handle($request);
    }
}

/** cookie_global(name): a cookie as site code that reads PHP's superglobals sees it. */
final class InternalRendersProvider extends ServiceProvider
{
    public function register(Container $container): void {}

    public function boot(Container $container): void
    {
        $container->get(TemplateEngineInterface::class)->addFunction(new TemplateFunctionDefinition(
            name: 'cookie_global',
            callable: static fn (string $name): string => is_string($_COOKIE[$name] ?? null) ? $_COOKIE[$name] : '',
        ));
    }
}
