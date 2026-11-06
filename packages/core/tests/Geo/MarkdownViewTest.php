<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Geo;

require_once __DIR__ . '/../Fixtures/Geo/GeoComponents.php';
require_once __DIR__ . '/../Fixtures/Geo/GeoTestRoutes.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\Geo\GeoMdCard;
use Preflow\Core\Tests\Fixtures\Geo\GeoMdPlain;
use Preflow\Core\Tests\Fixtures\Geo\GeoTestRoutes;
use Psr\Http\Message\ResponseInterface;

/** Markdown views of public pages: /page.md, /index.md and Accept: text/markdown (config/geo.php). */
final class MarkdownViewTest extends TestCase
{
    private string $dir;

    private const KEY = 'geo-markdown-test-key-0123456789ab';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/geo_md_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'" . self::KEY . "','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'fallback'=>'en','url_strategy'=>'prefix','cookie'=>true];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(GeoTestRoutes::class, true) . "];\n");
        $this->geo("['enabled' => true, 'exclude' => ['/secret']]");

        $card = addslashes(GeoMdCard::class);
        $plain = addslashes(GeoMdPlain::class);
        $page = static fn (string $title, string $main, string $head = ''): string =>
            '<html><head><title>' . $title . '</title>' . $head . '</head><body><nav><a href="/nav">Navigation</a></nav>'
            . '<main>' . $main . '</main><footer>Footer text</footer></body></html>';

        $this->page('index', $page('Home {{ current_locale() }}', '<h1>Welcome {{ current_locale() }}</h1><p>Read <a href="/about">about us</a>.</p>'));
        $this->page('about', $page(
            'About us',
            '<h1>About</h1><p>We play games.</p>'
            . '{{ component("' . $card . '", {title: "Alpha"}) }}'
            . '{{ component("' . $plain . '") }}'
            . '<img src="/img/logo.png" alt="Our logo"><img src="/img/deco.png" alt="">',
        ));
        $this->page('noindex', $page('No index', '<p>Do not index</p>', '<meta name="robots" content="noindex, follow">'));
        $this->page('secret', $page('Secret', '<p>Excluded page</p>'));
        $this->page('record', $page(
            'Record',
            '<p>Page HTML body</p>{{ component("' . $card . '", {title: "Inner"}) }}'
            . '{% apply geo %}## Facts' . "\n" . '- Players: 2{% endapply %}',
        ));
        $this->page('only-block', $page(
            'Only block',
            '<div data-geo="skip"><p>Skipped HTML</p></div>{% apply geo %}## Only the block{% endapply %}',
        ));
        $this->page('broken', $page('Broken', '{{ component("No\\\\Such\\\\Component") }}'));
        $this->page('page-a', $page('Page A', '<p>Page A text</p>{{ component("' . $card . '", {title: "A"}) }}'));
        $this->page('page-b', $page('Page B', '<p>Page B text</p>'));
        $this->page('dated', $page(
            'Dated',
            '<p>Dated text</p>',
            '<link rel="canonical" href="/dated-page"><meta property="og:updated_time" content="2026-11-05T23:30:00+01:00">',
        ));
        $this->page('bad-date', $page('Bad date', '<p>Text</p>', '<meta property="og:updated_time" content="not a date">'));
        $this->page('no-such-day', $page('No such day', '<p>Text</p>', '<meta property="og:updated_time" content="2026-02-30">'));
        $this->page('spaced', $page('Spaced', '<p>Source: BoardGameGeek</p>', '<link rel="canonical" href="/spiele/Catan Junior">'));
        @mkdir($this->dir . '/app/pages/blog', 0777, true);
        $this->page('blog/post', $page(
            'Post',
            '<p><a href="details">Details</a> <a href="/rooted">Rooted</a> <a href="https://other.test/x">Other</a>'
            . ' <img src="pics/a.png" alt="A picture"></p>',
        ));
    }

    protected function tearDown(): void
    {
        GeoTestRoutes::$app = null;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function page(string $name, string $twig): void
    {
        file_put_contents($this->dir . '/app/pages/' . $name . '.twig', $twig);
    }

    private function geo(?string $config): void
    {
        $path = $this->dir . '/config/geo.php';
        if ($config === null) {
            @unlink($path);
            return;
        }
        file_put_contents($path, "<?php\nreturn {$config};\n");
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();
        GeoTestRoutes::$app = $app;

        return $app;
    }

    /** @param array<string, string> $headers */
    private function get(Application $app, string $path, array $headers = [], string $method = 'GET'): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    private function assertNoMarkdown(ResponseInterface $response, string $path): void
    {
        $this->assertSame(404, $response->getStatusCode(), $path);
        $this->assertStringStartsWith('text/plain', $response->getHeaderLine('Content-Type'), $path);
        $this->assertSame([], $response->getHeader('Set-Cookie'), $path);
    }

    #[RunInSeparateProcess]
    public function test_md_url_returns_the_pages_markdown(): void
    {
        $res = $this->get($this->app(), '/about.md');
        $md = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode(), $md);
        $this->assertSame('text/markdown; charset=utf-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame('Accept', $res->getHeaderLine('Vary'));
        $this->assertSame('public, max-age=3600', $res->getHeaderLine('Cache-Control'));
        $this->assertSame([], $res->getHeader('Set-Cookie'));
        $this->assertStringStartsWith("# About us\n\nSource: https://site.test/about\n\n# About\n\nWe play games.", $md);
        $this->assertStringContainsString('## Card Alpha', $md);
        $this->assertStringNotContainsString('Card HTML', $md);
        $this->assertStringContainsString('[a relative link](https://site.test/contact)', $md);
        $this->assertStringContainsString('![Our logo](https://site.test/img/logo.png)', $md);
        $this->assertStringNotContainsString('deco.png', $md);
        $this->assertStringNotContainsString('Navigation', $md);
        $this->assertStringNotContainsString('Footer', $md);
        $this->assertStringNotContainsString('color: red', $md);

        $head = $this->get($this->app(), '/about.md', method: 'HEAD');
        $this->assertSame(200, $head->getStatusCode());
        $this->assertSame('text/markdown; charset=utf-8', $head->getHeaderLine('Content-Type'));
        $this->assertSame('', (string) $head->getBody());
    }

    #[RunInSeparateProcess]
    public function test_markdown_names_its_canonical_page_in_a_link_header(): void
    {
        $app = $this->app();

        // The page's address, or its own canonical link: the same URL as the Source line.
        $this->assertSame('<https://site.test/about>; rel="canonical"', $this->get($app, '/about.md')->getHeaderLine('Link'));
        $this->assertSame('<https://site.test/dated-page>; rel="canonical"', $this->get($app, '/dated.md')->getHeaderLine('Link'));
        $this->assertSame('<https://site.test/about>; rel="canonical"', $this->get($app, '/about', ['Accept' => 'text/markdown'])->getHeaderLine('Link'));
        // Only the header's Source line counts, and the URL is made safe for the header.
        $this->assertSame('<https://site.test/spiele/Catan%20Junior>; rel="canonical"', $this->get($app, '/spaced.md')->getHeaderLine('Link'));
        // No Markdown view, no canonical.
        $this->assertSame('', $this->get($app, '/secret.md')->getHeaderLine('Link'));
    }

    #[RunInSeparateProcess]
    public function test_markdown_names_the_date_the_page_was_last_changed(): void
    {
        $app = $this->app();

        // From the page's og:updated_time (SEO prints it from PageMeta::$modified), as a plain date.
        $this->assertStringStartsWith(
            "# Dated\n\nSource: https://site.test/dated-page\nUpdated: 2026-11-05\n\nDated text",
            (string) $this->get($app, '/dated.md')->getBody(),
        );
        // Without one, or with one that isn't a date, no line.
        $this->assertStringNotContainsString('Updated:', (string) $this->get($app, '/about.md')->getBody());
        $this->assertStringStartsWith("# Bad date\n\nSource: https://site.test/bad-date\n\nText", (string) $this->get($app, '/bad-date.md')->getBody());
        $this->assertStringNotContainsString('Updated:', (string) $this->get($app, '/no-such-day.md')->getBody(), 'no rollover to March');
    }

    #[RunInSeparateProcess]
    public function test_accept_text_markdown_on_the_page_url(): void
    {
        $app = $this->app();

        $md = $this->get($app, '/about', ['Accept' => 'text/markdown']);
        $this->assertSame(200, $md->getStatusCode());
        $this->assertSame('text/markdown; charset=utf-8', $md->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('# About us', (string) $md->getBody());

        $preferred = $this->get($app, '/about', ['Accept' => 'text/markdown, text/html;q=0.9']);
        $this->assertSame('text/markdown; charset=utf-8', $preferred->getHeaderLine('Content-Type'));

        foreach (['text/markdown;q=0.5, text/html', 'text/html,application/xhtml+xml,*/*;q=0.8', '*/*'] as $accept) {
            $html = $this->get($app, '/about', ['Accept' => $accept]);
            $this->assertStringStartsWith('text/html', $html->getHeaderLine('Content-Type'), $accept);
        }
    }

    #[RunInSeparateProcess]
    public function test_home_is_index_md(): void
    {
        $app = $this->app();

        $en = $this->get($app, '/index.md');
        $this->assertSame(200, $en->getStatusCode(), (string) $en->getBody());
        $this->assertStringStartsWith("# Home en\n\nSource: https://site.test/\n\n# Welcome en", (string) $en->getBody());
        $this->assertStringContainsString('[about us](https://site.test/about)', (string) $en->getBody());

        $de = $this->get($app, '/de/index.md');
        $this->assertSame(200, $de->getStatusCode(), (string) $de->getBody());
        $this->assertStringStartsWith("# Home de\n\nSource: https://site.test/de/\n\n# Welcome de", (string) $de->getBody());
    }

    /**
     * A session guard over one member, and a page that shows who is signed in and the flash.
     *
     * @param array<string, mixed> $session extra auth.session settings
     */
    private function memberSetup(array $session = []): string
    {
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users']],
            'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['id' => 'member', 'email' => 'member@site.test', 'password_hash' => '', 'roles' => []],
            ]]],
            'session' => ['cookie' => 'geo_test', 'secure' => false] + $session,
        ], true) . ";\n");
        $this->page('member', '<html><head><title>Member</title></head><body><main><p>Public text</p>'
            . '{% if auth_check() %}<p>Signed in as member</p>{% endif %}<p>{{ flash("success") }}</p></main></body></html>');

        return (string) (new \Preflow\Auth\ConfigUserProvider([['email' => 'member@site.test', 'password_hash' => '']]))
            ->findByCredentials(['email' => 'member@site.test'])?->getAuthId();
    }

    #[RunInSeparateProcess]
    public function test_signed_in_visitor_gets_the_anonymous_view_without_set_cookie(): void
    {
        $userId = $this->memberSetup();
        $app = $this->app();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_flash'] = ['current' => ['success' => 'Welcome back, member'], 'previous' => []];
        $cookie = ['Cookie' => 'geo_test=' . session_id(), 'Authorization' => 'Bearer secret'];

        $md = $this->get($app, '/member.md', $cookie);
        $body = (string) $md->getBody();

        $this->assertSame(200, $md->getStatusCode(), $body);
        $this->assertSame([], $md->getHeader('Set-Cookie'));
        $this->assertStringContainsString('Public text', $body);
        $this->assertStringNotContainsString('Signed in', $body);
        $this->assertStringNotContainsString('Welcome back', $body);
        // The visitor's own session is untouched by the internal render.
        $this->assertSame($userId, $_SESSION['_auth_user_id']);
        $this->assertSame('Welcome back, member', $_SESSION['_flash']['current']['success']);

        // The same visitor's HTML page shows them signed in, and the pipeline sets cookies there.
        $html = $this->get($app, '/member', $cookie);
        $this->assertStringContainsString('Signed in as member', (string) $html->getBody());
        $this->assertStringContainsString('Welcome back, member', (string) $html->getBody());
        $this->assertNotSame([], $html->getHeader('Set-Cookie'));
    }

    #[RunInSeparateProcess]
    public function test_internal_render_starts_no_session_from_the_visitors_cookie(): void
    {
        // session.paths: no session at boot; the session middleware would start one from the cookie.
        $userId = $this->memberSetup(['paths' => ['/']]);
        session_name('geo_test');
        session_start();
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_flash'] = ['current' => [], 'previous' => []];
        $sessionId = session_id();
        session_write_close();
        $_SESSION = [];
        $_COOKIE['geo_test'] = $sessionId;
        $app = $this->app();
        $this->assertSame(PHP_SESSION_NONE, session_status());

        $body = (string) $this->get($app, '/member.md', ['Cookie' => 'geo_test=' . $sessionId])->getBody();

        $this->assertStringContainsString('Public text', $body);
        $this->assertStringNotContainsString('Signed in', $body);
        $this->assertSame(PHP_SESSION_NONE, session_status());

        // Proof the cookie signs the visitor in on the HTML page.
        $html = (string) $this->get($app, '/member', ['Cookie' => 'geo_test=' . $sessionId])->getBody();
        $this->assertStringContainsString('Signed in as member', $html);
    }

    #[RunInSeparateProcess]
    public function test_noindex_meta_header_redirect_and_404_pages_have_no_markdown(): void
    {
        $app = $this->app();

        foreach (['/noindex.md', '/hidden.md', '/old.md', '/missing.md'] as $path) {
            $this->assertNoMarkdown($this->get($app, $path), $path);
        }

        foreach (['/noindex', '/hidden'] as $path) {
            // Asked for with Accept, a page without Markdown answers as usual.
            foreach ([[], ['Accept' => 'text/markdown']] as $headers) {
                $html = $this->get($app, $path, $headers);
                $this->assertSame(200, $html->getStatusCode(), $path);
                $this->assertStringStartsWith('text/html', $html->getHeaderLine('Content-Type'), $path);
                $this->assertSame([], $html->getHeader('Link'), $path);
                $this->assertStringNotContainsString('text/markdown', (string) $html->getBody(), $path);
            }
        }
    }

    #[RunInSeparateProcess]
    public function test_accept_markdown_without_a_markdown_view_gets_the_normal_response(): void
    {
        $app = $this->app();
        $accept = ['Accept' => 'text/markdown, text/html;q=0.9, */*;q=0.8'];

        $old = $this->get($app, '/old', $accept);
        $this->assertSame(302, $old->getStatusCode());
        $this->assertSame('/about', $old->getHeaderLine('Location'));

        $json = $this->get($app, '/data.json', $accept);
        $this->assertSame(200, $json->getStatusCode());
        $this->assertSame('application/json', $json->getHeaderLine('Content-Type'));
        $this->assertSame('{"ok":true}', (string) $json->getBody());

        $missing = $this->get($app, '/missing', $accept);
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertStringStartsWith('text/html', $missing->getHeaderLine('Content-Type'));

        // (The 404 is the error page, built outside the middleware, the same whatever the Accept header.)
        foreach (['/old' => $old, '/data.json' => $json] as $path => $response) {
            $this->assertContains('Accept', array_map('trim', explode(',', $response->getHeaderLine('Vary'))), $path);
        }

        // Only the .md URL is a 404.
        $this->assertNoMarkdown($this->get($app, '/old.md'), '/old.md');
    }

    #[RunInSeparateProcess]
    public function test_relative_links_resolve_against_the_page_url(): void
    {
        $md = (string) $this->get($this->app(), '/blog/post.md')->getBody();

        $this->assertStringContainsString('[Details](https://site.test/blog/details)', $md);
        $this->assertStringContainsString('[Rooted](https://site.test/rooted)', $md);
        $this->assertStringContainsString('[Other](https://other.test/x)', $md);
        $this->assertStringContainsString('![A picture](https://site.test/blog/pics/a.png)', $md);
    }

    #[RunInSeparateProcess]
    public function test_internal_render_runs_with_nobody_signed_in(): void
    {
        $userId = $this->memberSetup();
        $app = $this->app();
        $auth = $app->container()->get(\Preflow\Auth\AuthManager::class);
        $member = (new \Preflow\Auth\ConfigUserProvider([['id' => 'member', 'email' => 'member@site.test', 'password_hash' => '']]))
            ->findById($userId);
        $this->assertNotNull($member);
        $auth->setUser($member, 'session');

        // A render called from inside the pipeline, with a handler that skips the auth middleware.
        $seen = new \ArrayObject();
        $handler = new class ($auth, $seen) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private readonly \Preflow\Auth\AuthManager $auth, private readonly \ArrayObject $seen) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                $this->seen['user'] = $this->auth->user();
                $this->seen['users'] = $this->auth->users();

                return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'text/html'], '<html><head><title>X</title></head><body><main><p>X</p></main></body></html>');
            }
        };
        $geo = $app->container()->get(\Preflow\View\Geo\GeoMiddleware::class);
        $outer = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/member');

        $this->assertStringStartsWith('# X', (string) $geo->render($outer, $handler, '/member'));
        $this->assertNull($seen['user']);
        $this->assertSame([], $seen['users']);
        $this->assertSame($member, $auth->user());
        $this->assertSame('session', $auth->guardName());
    }

    #[RunInSeparateProcess]
    public function test_noindex_html_pages_have_no_alternate_link(): void
    {
        $app = $this->app();

        foreach (['/noindex', '/hidden'] as $path) {
            $html = $this->get($app, $path);
            $this->assertSame(200, $html->getStatusCode(), $path);
            $this->assertSame([], $html->getHeader('Link'), $path);
            $this->assertStringNotContainsString('text/markdown', (string) $html->getBody(), $path);
        }
    }

    #[RunInSeparateProcess]
    public function test_excluded_path_is_404(): void
    {
        $app = $this->app();

        $this->assertNoMarkdown($this->get($app, '/secret.md'), '/secret.md');
        $accept = $this->get($app, '/secret', ['Accept' => 'text/markdown']);
        $this->assertSame(200, $accept->getStatusCode());
        $this->assertStringContainsString('Excluded page', (string) $accept->getBody());
        $this->assertSame([], $accept->getHeader('Link'));

        $html = $this->get($app, '/secret');
        $this->assertSame(200, $html->getStatusCode());
        $this->assertSame([], $html->getHeader('Link'));
        $this->assertStringNotContainsString('text/markdown', (string) $html->getBody());
    }

    #[RunInSeparateProcess]
    public function test_html_pages_link_their_markdown_alternate(): void
    {
        $app = $this->app();

        $about = $this->get($app, '/about');
        $this->assertSame(['<https://site.test/about.md>; rel="alternate"; type="text/markdown"'], $about->getHeader('Link'));
        $this->assertStringContainsString('<link rel="alternate" type="text/markdown" href="https://site.test/about.md">', (string) $about->getBody());
        $this->assertSame(1, substr_count((string) $about->getBody(), 'type="text/markdown"'));
        $this->assertMatchesRegularExpression('#type="text/markdown" href="https://site.test/about.md">.*</head>#s', (string) $about->getBody());
        $this->assertContains('Accept', array_map('trim', explode(',', $about->getHeaderLine('Vary'))));

        $home = $this->get($app, '/?x=1');
        $this->assertSame(['<https://site.test/index.md>; rel="alternate"; type="text/markdown"'], $home->getHeader('Link'));

        $de = $this->get($app, '/de/');
        $this->assertStringContainsString('href="https://site.test/de/index.md"', (string) $de->getBody());

        $this->assertSame([], $this->get($app, '/missing')->getHeader('Link'));
        $this->assertSame([], $this->get($app, '/old')->getHeader('Link'));
    }

    #[RunInSeparateProcess]
    public function test_disabled_geo_changes_nothing(): void
    {
        $normalize = static fn (ResponseInterface $r): string => (string) preg_replace('#[A-Za-z0-9+/]{22}==#', 'NONCE', (string) $r->getBody());

        $this->geo(null);
        $plainApp = $this->app();
        $plain = $this->get($plainApp, '/about');
        $plainMissing = $this->get($plainApp, '/about.md');

        $this->geo("['enabled' => false]");
        $app = $this->app();
        $html = $this->get($app, '/about');
        $md = $this->get($app, '/about.md');

        $this->assertSame($normalize($plain), $normalize($html));
        $this->assertSame([], $html->getHeader('Link'));
        $this->assertStringNotContainsString('text/markdown', (string) $html->getBody());
        $this->assertSame($plain->getHeaderLine('Vary'), $html->getHeaderLine('Vary'));

        $this->assertSame(404, $md->getStatusCode());
        $this->assertSame($plainMissing->getHeaderLine('Content-Type'), $md->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('text/html', $md->getHeaderLine('Content-Type'));

        $accept = $this->get($app, '/about', ['Accept' => 'text/markdown']);
        $this->assertStringStartsWith('text/html', $accept->getHeaderLine('Content-Type'));
    }

    #[RunInSeparateProcess]
    public function test_page_template_geo_block_outside_components_is_used(): void
    {
        $res = $this->get($this->app(), '/record.md');
        $md = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode(), $md);
        // A block outside components leads the converted page; it doesn't replace it.
        $this->assertSame(
            "# Record\n\nSource: https://site.test/record\n\n## Facts\n- Players: 2\n\nPage HTML body\n\n## Card Inner\n",
            $md,
        );

        // A page that wants only its block marks its own HTML data-geo="skip".
        $only = (string) $this->get($this->app(), '/only-block.md')->getBody();
        $this->assertSame("# Only block\n\nSource: https://site.test/only-block\n\n## Only the block\n", $only);
    }

    #[RunInSeparateProcess]
    public function test_render_errors_are_logged_and_give_404(): void
    {
        $app = $this->app();
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $logs = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, (string) $message, $context];
            }
        };
        $app->container()->instance(\Psr\Log\LoggerInterface::class, $logger);

        $this->assertNoMarkdown($this->get($app, '/broken.md'), '/broken.md');
        $this->assertCount(1, $logger->logs);
        $this->assertSame('error', $logger->logs[0][0]);
        $this->assertStringContainsString('could not be rendered as Markdown', $logger->logs[0][1]);
        $this->assertInstanceOf(\Throwable::class, $logger->logs[0][2]['exception']);

        // Pages that simply aren't there are no error.
        $this->assertNoMarkdown($this->get($app, '/missing.md'), '/missing.md');
        $this->assertCount(1, $logger->logs);
    }

    #[RunInSeparateProcess]
    public function test_render_errors_show_the_error_page_in_debug_mode(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>1,'engine'=>'twig','key'=>'" . self::KEY . "','url'=>'https://site.test'];\n");

        $res = $this->get($this->app(), '/broken.md');

        $this->assertSame(500, $res->getStatusCode());
        $this->assertStringContainsString('No\\Such\\Component', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_each_render_gets_its_own_locale_and_the_request_keeps_its_own(): void
    {
        $app = $this->app();
        $seen = [];
        $app->container()->get(\Preflow\View\Geo\RenderScopes::class)->add(
            function (\Psr\Http\Message\ServerRequestInterface $sub, \Closure $next) use (&$seen): \Psr\Http\Message\ResponseInterface {
                $seen[] = $sub->getUri()->getPath();

                return $next();
            },
        );

        // The outer request: a German page, so the translator is in German.
        $outer = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/de/about');
        $app->handle($outer);
        $translator = $app->container()->get(\Preflow\I18n\Translator::class);
        $context = $app->container()->get(\Preflow\Core\Http\RequestContext::class);
        $this->assertSame('de', $translator->getLocale());

        // Renders as GeoMiddleware (outermost) makes them: through the whole application.
        $handler = new class ($app) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private readonly Application $app) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                return $this->app->handle($request);
            }
        };
        $geo = $app->container()->get(\Preflow\View\Geo\GeoMiddleware::class);
        $de = (string) $geo->render($outer, $handler, '/de/');
        $en = (string) $geo->render($outer, $handler, '/');

        $this->assertStringStartsWith('# Home de', $de);
        $this->assertStringStartsWith('# Home en', $en);
        $this->assertSame('de', $translator->getLocale());
        $this->assertSame($context, $app->container()->get(\Preflow\Core\Http\RequestContext::class));
        // Scopes packages add (Folio's content locale) wrap every render.
        $this->assertSame(['/de/', '/'], $seen);
    }

    #[RunInSeparateProcess]
    public function test_two_renders_in_one_request_are_independent(): void
    {
        $res = $this->get($this->app(), '/__two');
        $data = json_decode((string) $res->getBody(), true);

        $this->assertIsArray($data, (string) $res->getBody());
        $this->assertStringContainsString('## Card A', $data['a']);
        $this->assertStringContainsString('Page A text', $data['a']);
        $this->assertSame($data['alone'], $data['b']);
        $this->assertStringContainsString('Page B text', $data['b']);
        $this->assertStringNotContainsString('Card', $data['b']);
        // The request's own assets survive the renders, and none of theirs stay behind.
        $this->assertStringContainsString('.outer', $data['css']);
        $this->assertStringNotContainsString('.card', $data['css']);
        $this->assertFalse($data['capturing']);
    }
}
