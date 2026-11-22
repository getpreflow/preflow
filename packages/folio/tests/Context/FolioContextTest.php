<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Context;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Http\RequestLimits;
use Preflow\Folio\Content\Collections;
use Preflow\Folio\FolioText;
use Preflow\Folio\Http\AdminAuthMiddleware;
use Preflow\Folio\Http\AdminNav;
use Preflow\Folio\Http\LongRequest;
use Preflow\Folio\Tests\Fixtures\Context\ContextProbeProvider;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Translations\StoredOverrides;
use Preflow\Folio\Users\UserPreferences;
use Preflow\I18n\Translator;
use Preflow\Testing\ContextFactory;
use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\Audits;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Seo\PublicPages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Testing\ChecksFrameBalance;

/**
 * Since 0.81 Folio builds contexts and changes no service: an admin request runs within the
 * admin surface (the admin's interface language, overrides on) and leaves nothing behind; the
 * live preview renders as an anonymous visitor in the editing locale; the share preview narrows
 * its own request only; the SEO audit run from Folio leaves the admin's frame as it was; the
 * content locale, Folio's texts and the content memos follow the context.
 *
 * The site: content in en (the default) and de, visitors who read it in German (Accept-Language,
 * the cookie strategy), an admin who reads the admin in French, the admin's override
 * "Override save" of folio.common.save in German.
 */
final class FolioContextTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private ?Application $session = null;
    private string $token = '';
    private \Closure $autoload;

    protected function setUp(): void
    {
        Contexts::reset();
        ContextProbeProvider::$seen = ContextProbeProvider::$after = [];
        ContextProbeProvider::$write = ContextProbeProvider::$fail = ContextProbeProvider::$mail = false;
        $this->scaffoldPublishing();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-integration-test-key-0123456789abcdef', 'url' => 'https://site.test'];\n");
        // Content in en (the default) and de; the visitor's locale comes from Accept-Language (send()).
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'cookie', 'cookie' => false];\n");
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
        $this->write('resources/folio/frontend/post.twig', '<html><head><title>{{ record.title }}</title></head><body><h1>{{ record.title }}</h1>'
            . '{% if auth_check() %}<b class="members">MEMBERS</b>{% endif %}<p class="probe">{{ context_probe() }}</p>{{ probe_cookie() }}{{ probe_effects() }}</body></html>');
        $this->write('app/pages/index.twig', '<p class="home">{{ context_probe() }}|{{ interface_locale }}|{{ t("folio.common.save") }}|{{ folio_url("post", "p1")|default("none") }}</p>');
        $publisher = $this->publisher();
        $publisher->publish($this->working('post', 'p1', ['title' => ['de' => 'Hallo', 'en' => 'Hello'], 'slug' => ['de' => 'hallo', 'en' => 'hello'], '_locales' => ['de', 'en']]), null);

        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        FolioText::translatorFrom(null);
        Contexts::reset();
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_admin_requests_run_within_the_admin_surface_and_leave_no_state(): void
    {
        $this->dashboardOverride();
        $this->signIn();
        $this->session->runAfterResponseInHandle();
        $this->session->container()->get(StoredOverrides::class)->set('de', 'folio.common.save', 'Override save', null);

        $admin = $this->send('GET', '/folio');

        $this->assertSame(200, $admin->getStatusCode());
        $body = (string) $admin->getBody();
        $this->assertStringContainsString('<html lang="fr">', $body, 'the interface language, through the lazy engine global');
        // The override page: admin surface in fr; content in the default content locale (as in 0.80),
        // although the editor's browser asks for de: folio_url() gives the en address.
        $this->assertStringContainsString('<p class="probe">en|admin:fr:overrides|working|admin@site.test|visit</p><a class="url">/hello</a>', $body);
        $this->assertSame(['de|public|working|admin@site.test|visit'], ContextProbeProvider::$after, 'work after the response: working copies as in 0.80, the visitor\'s locale and texts');
        $this->assertSame(0, Contexts::depth());

        // Another browser: no session, nothing of the admin request.
        $_SESSION = ['_flash' => ['current' => [], 'previous' => []]];
        $home = (string) $this->send('GET', '/')->getBody();

        $this->assertStringContainsString('<p class="home">de|public|published|nobody|visit|en|Speichern|/hallo</p>', $home, 'nobody signed in, the visitor\'s locale, the default interface locale, no overrides');
    }

    #[RunInSeparateProcess]
    public function test_live_preview_renders_as_an_anonymous_visitor(): void
    {
        $this->signIn();

        $preview = $this->send('POST', '/folio/post/p1/preview', ['title' => 'Entwurf', 'slug' => 'hallo', '_locale' => 'de']);

        $this->assertSame(200, $preview->getStatusCode());
        $body = (string) $preview->getBody();
        $this->assertStringContainsString('<h1>Entwurf</h1>', $body);
        $this->assertStringNotContainsString('MEMBERS', $body, 'the signed-in editor is not who the page renders for');
        $this->assertStringContainsString('<p class="probe">de|public|working|nobody|preview</p>', $body, 'nothing it renders leaves a trace');
    }

    #[RunInSeparateProcess]
    public function test_live_preview_leaves_the_admins_locale_and_identity(): void
    {
        $this->signIn();

        $preview = (string) $this->send('POST', '/folio/post/p1/preview', ['title' => 'Draft', 'slug' => 'hello', '_locale' => 'en'])->getBody();

        $this->assertStringContainsString('<p class="probe">en|public|working|nobody|preview</p>', $preview, 'the editing locale, set explicitly');
        $this->assertSame(0, Contexts::depth());
        $this->assertStringContainsString('<p class="home">de|public|published|admin@site.test|visit|en|Speichern|/hallo</p>', (string) $this->send('GET', '/')->getBody(), 'the content locale is the visitor\'s, not the preview\'s en');
        $admin = $this->send('GET', '/folio');
        $this->assertSame(200, $admin->getStatusCode(), 'still signed in');
        $this->assertStringContainsString('<html lang="fr">', (string) $admin->getBody());
    }

    #[RunInSeparateProcess]
    public function test_share_preview_read_mode_ends_with_its_request(): void
    {
        $this->working('post', 'p1', ['title' => ['de' => 'Entwurf', 'en' => 'Draft'], 'slug' => ['de' => 'hallo', 'en' => 'hello'], '_locales' => ['de', 'en']]);
        $this->signIn();
        $share = (string) $this->send('POST', '/folio/post/p1/share', ['_locale' => 'de'])->getBody();
        $this->assertSame(1, preg_match('/folio_preview=([A-Za-z0-9_.\-]+)/', $share, $m), $share);
        $this->session->runAfterResponseInHandle();
        Contexts::onEnter(static function (RenderContext $ctx, \Preflow\Core\Context\RenderFrame $frame): null {
            $frame->outbox()->afterResponse(static function (): void {
                ContextProbeProvider::$after[] = ContextProbeProvider::probe();
            });

            return null;
        }, 'folio-context-test');

        $shared = $this->session->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/hallo?folio_preview=' . $m[1])
            ->withHeader('Accept-Language', 'de')->withQueryParams(['folio_preview' => $m[1]]));
        $this->assertStringContainsString('<h1>Entwurf</h1>', (string) $shared->getBody());
        // A real visit of the shared draft (since 0.81.0 no internal Preview: the site's own error page, the debug toolbar).
        $this->assertStringContainsString('<p class="probe">de|public|working|admin@site.test|visit</p>', (string) $shared->getBody());

        $public = (string) $this->send('GET', '/hallo')->getBody();

        $this->assertStringContainsString('<h1>Hallo</h1>', $public, 'the next request reads published');
        $this->assertSame('de|public|published|admin@site.test|visit', end(ContextProbeProvider::$after), 'and so does its after-response work');
    }

    /**
     * The live preview writes nothing and sends nothing with the admin's request (since 0.81.0): a
     * page that writes on GET is skipped, the cookie it queues is captured, never set.
     */
    #[RunInSeparateProcess]
    public function test_live_preview_skips_writes_and_keeps_what_it_would_send(): void
    {
        $this->signIn();
        ContextProbeProvider::$write = ContextProbeProvider::$mail = true;
        $notes = fn (): int => count($this->session->container()->get(\Preflow\Data\DataManager::class)->queryType('note')->get()->items());
        $before = $notes();

        $preview = $this->send('POST', '/folio/post/p1/preview', ['title' => 'Draft', 'slug' => 'hello', '_locale' => 'en']);

        $this->assertSame(200, $preview->getStatusCode());
        $this->assertSame($before, $notes(), 'the write was skipped');
        $this->assertSame([], $this->session->container()->get(\Preflow\Notify\TransportFactory::class)->get('mail')->sent(), 'the mail was not sent with the admin\'s request');
        $this->assertSame(0, $this->session->container()->get(\Preflow\Notify\Notifier::class)->flushDeferred(), 'nor left to be sent');
        $this->assertStringNotContainsString('probe=', implode("\n", $preview->getHeader('Set-Cookie')), 'the page\'s cookie is not set with the admin\'s response');
        $this->assertSame(0, Contexts::depth());
    }

    /** A live preview that fails leaves the admin request as it was: their locale, texts and identity. */
    #[RunInSeparateProcess]
    public function test_an_error_in_the_live_preview_restores_the_admins_locale_and_identity(): void
    {
        $this->signIn();
        ContextProbeProvider::$fail = true;

        $preview = $this->send('POST', '/folio/post/p1/preview', ['title' => 'Draft', 'slug' => 'hello', '_locale' => 'en']);

        $this->assertSame(500, $preview->getStatusCode());
        $this->assertStringContainsString('The probed page failed.', (string) $preview->getBody(), 'the admin\'s own (debug) error page');
        $this->assertSame(0, Contexts::depth());
        ContextProbeProvider::$fail = false;
        $admin = $this->send('GET', '/folio');
        $this->assertSame(200, $admin->getStatusCode(), 'still signed in');
        $this->assertStringContainsString('<html lang="fr">', (string) $admin->getBody(), 'the admin\'s interface language');
    }

    /**
     * The share preview is a real person's visit of the draft: a page that fails shows the site's
     * error page for that visit (the detailed one in debug mode, as on any page), not the bare page
     * of an internal render; a page that writes on GET is skipped.
     */
    #[RunInSeparateProcess]
    public function test_share_preview_is_a_visit_that_writes_nothing(): void
    {
        $this->working('post', 'p1', ['title' => ['de' => 'Entwurf', 'en' => 'Draft'], 'slug' => ['de' => 'hallo', 'en' => 'hello'], '_locales' => ['de', 'en']]);
        $this->signIn();
        $share = (string) $this->send('POST', '/folio/post/p1/share', ['_locale' => 'de'])->getBody();
        $this->assertSame(1, preg_match('/folio_preview=([A-Za-z0-9_.\-]+)/', $share, $m), $share);
        $shared = fn (): ResponseInterface => $this->session->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/hallo?folio_preview=' . $m[1])
            ->withHeader('Accept-Language', 'de')->withQueryParams(['folio_preview' => $m[1]]));
        $notes = fn (): int => count($this->session->container()->get(\Preflow\Data\DataManager::class)->queryType('note')->get()->items());
        $before = $notes();

        ContextProbeProvider::$write = true;
        $page = $shared();
        $this->assertStringContainsString('<h1>Entwurf</h1>', (string) $page->getBody());
        $this->assertSame($before, $notes(), 'the write was skipped');
        $this->assertSame('private, no-store', $page->getHeaderLine('Cache-Control'));

        ContextProbeProvider::$fail = true;
        $failed = $shared();
        $this->assertSame(500, $failed->getStatusCode());
        $this->assertStringContainsString('The probed page failed.', (string) $failed->getBody(), 'the debug error page of a visit, not the internal render\'s bare one');
        $this->assertSame(0, Contexts::depth());
    }

    /**
     * A share preview's page that mails on GET: the mail is captured with the page (it would tell
     * someone of a write that was skipped); an ordinary page's mail still goes out.
     */
    #[RunInSeparateProcess]
    public function test_share_preview_captures_the_mail_of_the_page(): void
    {
        $this->working('post', 'p1', ['title' => ['de' => 'Entwurf', 'en' => 'Draft'], 'slug' => ['de' => 'hallo', 'en' => 'hello'], '_locales' => ['de', 'en']]);
        $this->signIn();
        $share = (string) $this->send('POST', '/folio/post/p1/share', ['_locale' => 'de'])->getBody();
        $this->assertSame(1, preg_match('/folio_preview=([A-Za-z0-9_.\-]+)/', $share, $m), $share);
        $sent = fn (): array => $this->session->container()->get(\Preflow\Notify\TransportFactory::class)->get('mail')->sent();
        ContextProbeProvider::$mail = true;

        $shared = $this->session->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/hallo?folio_preview=' . $m[1])
            ->withHeader('Accept-Language', 'de')->withQueryParams(['folio_preview' => $m[1]]));

        $this->assertStringContainsString('<h1>Entwurf</h1>', (string) $shared->getBody());
        $this->assertStringContainsString('<p class="probe">de|public|working|admin@site.test|visit</p>', (string) $shared->getBody(), 'the page\'s locale and read mode');
        $this->assertSame([], $sent(), 'the shared draft\'s mail is captured');
        $this->assertSame(0, $this->session->container()->get(\Preflow\Notify\Notifier::class)->flushDeferred());
        $this->assertSame(0, Contexts::depth());

        // Proof: the published page, visited, sends its mail.
        $this->send('GET', '/hallo');
        $this->assertCount(1, $sent());
    }

    #[RunInSeparateProcess]
    public function test_audit_from_folio_keeps_the_admin_frame_untouched(): void
    {
        $this->signIn();
        $this->oneAuditPagePerStep();

        $step = $this->send('POST', '/folio/seo-audit/step', htmx: true);

        $this->assertSame(200, $step->getStatusCode());
        $audited = array_values(array_filter(ContextProbeProvider::$seen, static fn (string $p): bool => str_ends_with($p, '|audit')));
        $this->assertNotEmpty($audited, 'a page was audited');
        foreach ($audited as $probe) {
            $this->assertMatchesRegularExpression('/^(en|de)\|public\|published\|nobody\|audit$/', $probe, 'as a visitor');
        }
        $this->assertStringNotContainsString('probe=', implode("\n", $step->getHeader('Set-Cookie')), 'what the audited page queued stays in its frame');
        $this->assertTrue($step->hasHeader('HX-Redirect') || str_contains((string) $step->getBody(), 'audit'), 'the progress partial, or the way to the finished report');
        $this->assertSame(0, Contexts::depth());
        $admin = $this->send('GET', '/folio/seo-audit');
        $this->assertSame(200, $admin->getStatusCode(), 'still signed in');
        $this->assertStringContainsString('<html lang="fr">', (string) $admin->getBody(), 'the admin\'s interface language');
    }

    #[RunInSeparateProcess]
    public function test_collections_memo_follows_the_context(): void
    {
        $this->working('post', 'p1', ['title' => ['de' => 'Entwurf', 'en' => 'Draft'], 'slug' => ['de' => 'hallo', 'en' => 'hello'], '_locales' => ['de', 'en']]);
        $app = $this->app();
        $collections = $app->container()->get(Collections::class);
        $titles = static fn (): array => array_column($collections->list('post'), 'title');

        Contexts::run(RenderContext::console($app->site(), new \Preflow\Core\Context\SystemClock())->in('de'), function () use ($titles): void {
            $this->assertSame(['Hallo'], $titles());
            $this->assertSame(['Entwurf'], Contexts::within(Contexts::current()->workingCopies(), $titles), 'working copies: read anew');
            $this->assertSame(['Hello'], Contexts::within(Contexts::current()->in('en'), $titles), 'another locale: read anew');
            $this->assertSame(['Hallo'], $titles(), 'published again: the first list');
        });
        Contexts::run(RenderContext::console($app->site(), new \Preflow\Core\Context\SystemClock())->in('de')->workingCopies(), function () use ($titles): void {
            $this->assertSame(['Entwurf'], $titles(), 'a frame of its own starts empty');
        });
    }

    #[RunInSeparateProcess]
    public function test_folio_text_follows_the_surface(): void
    {
        $app = $this->app();
        $translator = $app->container()->get(Translator::class);
        $site = $app->site();

        $this->assertSame('Save', FolioText::get('folio.common.save'), 'outside every render: English');
        Contexts::run(RenderContext::console($site, new \Preflow\Core\Context\SystemClock()), function () use ($translator): void {
            $this->assertSame('Save', FolioText::get('folio.common.save'));
            $this->assertSame('Speichern', Contexts::within(Contexts::current()->in('de'), static fn (): string => FolioText::get('folio.common.save')), 'public surface: Folio\'s own texts in the render\'s locale');
            Contexts::within(Contexts::current()->admin('fr'), function () use ($translator): void {
                $this->assertSame('Enregistrer', FolioText::get('folio.common.save'));
                $this->assertSame('fr', FolioText::locale());
                $this->assertSame($translator, FolioText::translator(), 'the app\'s translator');
            });
            $this->assertNotSame($translator, FolioText::translator(), 'the stretch ended');
        });
        $this->assertSame('Save', FolioText::get('folio.common.save'));
    }

    #[RunInSeparateProcess]
    public function test_admin_routes_refuse_a_synthetic_viewer(): void
    {
        $this->signIn();
        $app = $this->session;
        $role = new RoleView('staff', ['admin']);
        $asRole = RenderContext::console($app->site(), new \Preflow\Core\Context\SystemClock())->asRole($role);
        $f = new Psr17Factory();

        // A real htmx POST (an audit step) and the sign-in page (no AdminAuthMiddleware), handled inside a role view's frame
        // (view as role): refused, and nothing ran.
        $step = Contexts::run($asRole, static fn (): ResponseInterface => $app->handle($f->createServerRequest('POST', '/folio/seo-audit/step')
            ->withHeader('HX-Request', 'true')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([])));
        $login = Contexts::run($asRole, static fn (): ResponseInterface => $app->handle($f->createServerRequest('GET', '/folio/login')));

        $this->assertSame(403, $step->getStatusCode());
        $this->assertSame(403, $login->getStatusCode());
        $this->assertFileDoesNotExist(AuditReport::file($this->dir), 'no audit step ran');

        // Its SyntheticUser on the request: refused by AdminAuthMiddleware too.
        $handler = new class implements RequestHandlerInterface {
            public bool $reached = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->reached = true;

                return new \Nyholm\Psr7\Response(200);
            }
        };
        $middleware = new AdminAuthMiddleware($app->container()->get(\Preflow\Auth\AuthManager::class), '/folio', $app->container()->get(AdminNav::class));
        $asUser = Contexts::run(RenderContext::console($app->site(), new \Preflow\Core\Context\SystemClock()), static fn (): ResponseInterface => $middleware->process(
            $f->createServerRequest('GET', '/folio/post')->withAttribute(\Preflow\Auth\Authenticatable::class, new \Preflow\Auth\SyntheticUser($role)),
            $handler,
        ));
        $this->assertSame(403, $asUser->getStatusCode());
        $this->assertFalse($handler->reached, 'no admin route (an import, the extensions file) is reached');
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'the real admin still gets in');
    }

    #[RunInSeparateProcess]
    public function test_admin_routes_refuse_a_synthetic_viewer_without_auth_config(): void
    {
        $app = $this->app(); // no config/auth.php: no AdminAuthMiddleware on the admin routes
        $asRole = RenderContext::console($app->site(), new \Preflow\Core\Context\SystemClock())->asRole(new RoleView('staff', ['admin']));

        $response = Contexts::run($asRole, static fn (): ResponseInterface => $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio')));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(200, $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio'))->getStatusCode(), 'a real request gets in');
    }

    #[RunInSeparateProcess]
    public function test_every_admin_route_starts_with_the_synthetic_viewer_refusal(): void
    {
        $this->signIn();
        $this->dashboardOverride();
        $this->write('app/Folio/Stats/Screens/Board.php', <<<'PHP'
            <?php
            namespace App\Folio\Stats\Screens;
            #[\Preflow\Folio\Extension\Attributes\FolioScreen('board', 'Board', permission: 'stats.view')]
            final class Board
            {
                #[\Preflow\Routing\Attributes\Get('/')]
                public function index(): \Preflow\Folio\Extension\Screen\ScreenView
                {
                    return new \Preflow\Folio\Extension\Screen\ScreenView('@stats/board.twig');
                }

                #[\Preflow\Routing\Attributes\Post('/refresh')]
                public function refresh(): \Psr\Http\Message\ResponseInterface
                {
                    return new \Nyholm\Psr7\Response(204);
                }
            }
            PHP);
        $app = $this->app();
        $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio/login')); // the admin's routes are set up
        $router = $app->container()->get(\Preflow\Core\Routing\RouterInterface::class);
        $static = ['Preflow\\Folio\\Http\\AssetController', 'Preflow\\Folio\\Http\\UploadController', 'Preflow\\Folio\\Http\\ExtensionAssetController'];
        $signedOut = ['Preflow\\Folio\\Http\\AuthController', 'Preflow\\Folio\\Http\\PasswordController'];

        $checked = [];
        foreach ($router->getCollection()->all() as $route) {
            if (!str_starts_with($route->pattern, '/folio/') && $route->pattern !== '/folio') {
                continue;
            }
            $class = explode('@', is_string($route->handler) ? $route->handler : '')[0];
            if (in_array($class, $static, true)) {
                $this->assertSame([], $route->middleware, $route->pattern . ': a static file');
                continue;
            }
            $where = $route->method . ' ' . $route->pattern;
            $this->assertSame(\Preflow\Folio\Http\WorkingCopyMiddleware::class, $route->middleware[0] ?? null, $where);
            if (!in_array($class, $signedOut, true)) {
                $this->assertContains(AdminAuthMiddleware::class, $route->middleware, $where);
            }
            $checked[] = $where;
        }

        $this->assertContains('GET /folio/board', $checked, 'an extension screen');
        $this->assertContains('POST /folio/board/refresh', $checked, 'a screen action');
        $this->assertContains('POST /folio/seo-audit/step', $checked, 'an htmx fragment');
        $this->assertContains('POST /folio/login', $checked, 'the sign-in page');
        $this->assertGreaterThan(50, count($checked));
    }

    #[RunInSeparateProcess]
    public function test_an_admin_request_inside_another_frame_reads_overrides_afresh(): void
    {
        $this->signIn();
        $app = $this->session;
        $overrides = $app->container()->get(StoredOverrides::class);
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);

        $pages = Contexts::run(RenderContext::console($app->site(), new \Preflow\Core\Context\SystemClock()), function () use ($overrides, $dm): array {
            $first = (string) $this->send('GET', '/folio')->getBody();
            // Another process saves an override: its row, and the compiled file gone.
            $overrides->ensureTable();
            $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($overrides->definition(), ['id' => 'fr:folio.layout.log_out', 'locale' => 'fr', 'key' => 'folio.layout.log_out', 'text' => 'Partir']), false);
            @unlink($this->dir . '/storage/cache/folio-translations-fr.php');

            return [$first, (string) $this->send('GET', '/folio')->getBody()];
        });

        $this->assertStringContainsString('Se déconnecter', $pages[0]);
        $this->assertStringContainsString('Partir', $pages[1], 'the outermost admin request refreshed the overrides');
    }

    public function test_long_requests_record_their_limits_on_the_frame(): void
    {
        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/seo-audit/run');
        $before = (int) ini_get('max_execution_time');
        $abort = (bool) ignore_user_abort();

        try {
            $limits = Contexts::run(ContextFactory::forTest(), static function () use ($request): RequestLimits {
                LongRequest::allow($request, 120);

                return Contexts::frame()->slot(RequestLimits::class, static fn (): RequestLimits => new RequestLimits());
            });
        } finally {
            set_time_limit($before);
            ignore_user_abort($abort);
        }

        $this->assertTrue($limits->ignoresUserAbort);
        $this->assertCount(1, $limits->timeLimits);
        $this->assertSame(120, $limits->timeLimits[0]['seconds']);
    }

    /** A dashboard override returning a ScreenView (rendered through AdminPage), printing the probe and queueing after-response work. */
    private function dashboardOverride(): void
    {
        $this->write('app/Folio/Stats/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Stats;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'stats', label: 'Stats')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Stats/templates/board.twig', "{% extends '@folio/admin/_layout.twig' %}{% block content %}<p class=\"probe\">{{ context_probe() }}</p><a class=\"url\">{{ folio_url('post', 'p1') }}</a>{{ context_after() }}{% endblock %}");
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => ['stats' => new \stdClass()]]));
        $this->write('app/Folio/Overrides/Content/Index.php', <<<'PHP'
            <?php
            namespace App\Folio\Overrides\Content;
            final class Index implements \Preflow\Folio\Override\OverridableAction
            {
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface|\Preflow\Folio\Extension\Screen\ScreenView
                {
                    return new \Preflow\Folio\Extension\Screen\ScreenView('@stats/board.twig');
                }
            }
            PHP);
    }

    /** The app's Audits on a clock where each reading is 100 s later: one page per step. */
    private function oneAuditPagePerStep(): void
    {
        $c = $this->session->container();
        $clock = new \DateTimeImmutable('2026-11-13T10:00:00+00:00');
        $c->instance(Audits::class, new Audits(
            $c->get(AuditRegistry::class),
            $c->get(PageRenderer::class),
            $c->get(PublicPages::class),
            AuditReport::file($this->dir),
            static function () use (&$clock): \DateTimeImmutable {
                return $clock = $clock->modify('+100 seconds');
            },
        ));
    }

    /** Auth with one admin who reads the admin in French, signed in on one in-process app. */
    private function signIn(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'session' => ['cookie' => 'folio_context_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => ['admin' => ['*']]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class, \\Preflow\\Folio\\Tests\\Fixtures\\Context\\ContextProbeProvider::class];\n");

        $this->session = $this->app();
        $this->session->container()->get(UserPreferences::class)->setLocale('admin@site.test', 'fr');
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, string> $body */
    private function send(string $method, string $uri, array $body = [], bool $htmx = false): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri)->withHeader('Accept-Language', 'de');
        if ($method !== 'GET') {
            $req = $req->withHeader('Sec-Fetch-Site', 'same-origin');
            if (session_id() !== '') {
                $req = $req->withCookieParams([session_name() => session_id()]);
            }
            $req = $req->withParsedBody(['_csrf_token' => $this->token] + $body);
        }
        if ($htmx) {
            $req = $req->withHeader('HX-Request', 'true');
        }

        return $this->session->handle($req);
    }
}
