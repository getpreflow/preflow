<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Seo;

require_once __DIR__ . '/../Fixtures/Seo/SeoSiteProvider.php';
require_once __DIR__ . '/../Fixtures/Seo/SeoTestRoutes.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\Seo\SeoSiteProvider;
use Preflow\Core\Tests\Fixtures\Seo\SeoTestRoutes;
use Preflow\View\NonceGenerator;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\PublicPages;
use Preflow\View\Seo\SchemaRegistry;
use Preflow\View\Seo\SeoConfig;
use Preflow\View\Seo\SeoContext;
use Preflow\View\Seo\SeoMiddleware;
use Preflow\View\Seo\SeoSite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Head tags and JSON-LD on HTML pages, and ETag / 304 for responses that are the same for everyone. */
final class SeoHeadAndCachingTest extends TestCase
{
    private string $dir;

    private const KEY = 'seo-head-test-key-0123456789abcdef';

    private const OWNER = "'owner' => ['name' => 'Game Club AG', 'url' => 'https://site.test', 'same_as' => ['https://social.test/gc']]";

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/seo_head_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        $this->appConfig("'App Name'");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'fallback'=>'en','url_strategy'=>'prefix'];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(SeoTestRoutes::class, true) . "];\n");
        $this->seo("['enabled' => true, 'name' => 'Game Club', 'theme_color' => '#112233', " . self::OWNER . "]");

        $page = static fn (string $title, string $main, string $head = ''): string =>
            '<html><head><title>' . $title . '</title>' . $head . '</head><body><main>' . $main . '</main>'
            . '<script nonce="{{ csp_nonce() }}">init()</script></body></html>';
        $this->page('index', $page('Home', '<h1>Welcome {{ current_locale() }}</h1>'));
        $this->page('about', '{% do seo({title: "About us", description: "Who <b>we</b> are", image: "/img/share.jpg", schema: {"@type": "Event", name: "Game night"}}) %}'
            . $page('About', '<p>We play games.</p>'));
        $this->page('plain', $page('Plain', '<p>No seo() call.</p>'));
        $this->page('secret', '{% do seo({title: "Secret", noindex: true}) %}' . $page('Secret', '<p>Hidden</p>'));
        $this->page('own-robots', $page('Own robots', '<p>Hidden</p>', '<meta name="robots" content="noindex, follow">'));
        $this->page('inner', '{% do seo({title: "Inner"}) %}' . $page('Inner', '<p>Inner text</p>'));

        SeoSiteProvider::reset();
    }

    protected function tearDown(): void
    {
        SeoSiteProvider::reset();
        SeoTestRoutes::$app = null;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function appConfig(string $name): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>{$name},'debug'=>0,'engine'=>'twig','key'=>'" . self::KEY . "','url'=>'https://site.test'];\n");
    }

    private function page(string $name, string $twig): void
    {
        file_put_contents($this->dir . '/app/pages/' . $name . '.twig', $twig);
    }

    private function seo(string $config): void
    {
        file_put_contents($this->dir . '/config/seo.php', "<?php\nreturn {$config};\n");
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();
        SeoTestRoutes::$app = $app;

        return $app;
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = [], ?Application $app = null): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (isset($headers['Cookie'])) {
            $cookies = [];
            foreach (explode(';', $headers['Cookie']) as $pair) {
                [$name, $value] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
                $cookies[$name] = $value;
            }
            $request = $request->withCookieParams($cookies);
        }

        return ($app ?? $this->app())->handle($request);
    }

    /** @return list<array> the JSON-LD graph in $html */
    private function graph(string $html): array
    {
        $this->assertSame(1, preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m), $html);
        $json = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org', $json['@context']);

        return $json['@graph'];
    }

    /** @return list<string> the graph's node types */
    private function types(string $html): array
    {
        return array_column($this->graph($html), '@type');
    }

    #[RunInSeparateProcess]
    public function test_cards_on_a_plain_page(): void
    {
        $response = $this->get('/about?ref=x');
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), $html);
        foreach ([
            '<meta name="robots" content="max-image-preview:large">',
            '<meta name="theme-color" content="#112233">',
            '<meta property="og:site_name" content="Game Club">',
            '<meta property="og:title" content="About us">',
            '<meta property="og:description" content="Who we are">',
            '<meta property="og:type" content="website">',
            '<meta property="og:url" content="https://site.test/about">',
            '<meta property="og:locale" content="en">',
            '<meta property="og:image" content="https://site.test/img/share.jpg">',
            '<meta name="twitter:card" content="summary_large_image">',
        ] as $tag) {
            $this->assertStringContainsString($tag, $html);
        }
        // The layout's own <title> stays; the tags go before </head>.
        $this->assertStringContainsString('<title>About</title>', $html);
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, 'og:title'));

        $graph = $this->graph($html);
        $this->assertSame(['WebPage', 'Event'], array_column($graph, '@type'));
        $this->assertSame('https://site.test/about#webpage', $graph[0]['@id']);
        $this->assertSame('About us', $graph[0]['name']);
        $this->assertSame('Who we are', $graph[0]['description']);
        $this->assertSame(['@id' => 'https://site.test/#website'], $graph[0]['isPartOf']);

        // A page that says nothing still gets its URL, locale, its <title> as title and a WebPage node.
        $plain = (string) $this->get('/plain')->getBody();
        $this->assertStringContainsString('<meta property="og:url" content="https://site.test/plain">', $plain);
        $this->assertStringContainsString('<meta property="og:title" content="Plain">', $plain);
        $this->assertSame(['WebPage'], $this->types($plain));

        // No <title> either: no og:title.
        $this->page('untitled', '<html><head></head><body><p>Untitled</p></body></html>');
        $this->assertStringNotContainsString('og:title', (string) $this->get('/untitled')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_home_has_website_and_owner(): void
    {
        foreach (['/' => 'en', '/de/' => 'de', '/de' => 'de'] as $path => $locale) {
            $html = (string) $this->get($path)->getBody();
            $graph = $this->graph($html);

            $this->assertSame(['WebSite', 'Organization', 'WebPage'], array_column($graph, '@type'), $path);
            $this->assertSame('https://site.test/#website', $graph[0]['@id']);
            $this->assertSame('Game Club', $graph[0]['name']);
            $this->assertSame($locale, $graph[0]['inLanguage'], $path);
            $this->assertSame(['@id' => 'https://site.test/#owner'], $graph[0]['publisher']);
            $this->assertSame('Game Club AG', $graph[1]['name']);
            $this->assertSame(['https://social.test/gc'], $graph[1]['sameAs']);
            $this->assertStringContainsString('<meta property="og:locale" content="' . $locale . '">', $html, $path);
        }

        $this->assertSame(['WebPage', 'Event'], $this->types((string) $this->get('/about')->getBody()));
    }

    #[RunInSeparateProcess]
    public function test_noindex_pages_get_the_robots_tag_only(): void
    {
        $secret = (string) $this->get('/secret')->getBody();
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $secret);
        $this->assertStringNotContainsString('og:', $secret);
        $this->assertStringNotContainsString('ld+json', $secret);

        // Kept out of search by the page's own tag or by header: no cards either.
        foreach (['/own-robots', '/hidden'] as $path) {
            $html = (string) $this->get($path)->getBody();
            $this->assertStringNotContainsString('og:', $html, $path);
            $this->assertStringNotContainsString('ld+json', $html, $path);
        }
        $this->assertSame(1, substr_count((string) $this->get('/own-robots')->getBody(), 'name="robots"'));
    }

    #[RunInSeparateProcess]
    public function test_private_pages_get_no_cards_or_json_ld(): void
    {
        foreach (['/private', '/no-store'] as $path) {
            $html = (string) $this->get($path)->getBody();
            $this->assertStringContainsString('<meta name="robots" content="max-image-preview:large">', $html, $path);
            $this->assertStringContainsString('<meta name="theme-color" content="#112233">', $html, $path);
            $this->assertStringNotContainsString('og:', $html, $path);
            $this->assertStringNotContainsString('twitter:', $html, $path);
            $this->assertStringNotContainsString('ld+json', $html, $path);
        }
    }

    #[RunInSeparateProcess]
    public function test_site_defaults_from_seo_site(): void
    {
        $this->seo("['enabled' => true]");
        SeoSiteProvider::$defaults = static fn (string $locale): PageMeta => new PageMeta(
            title: 'Site ' . $locale,
            description: 'Board games in ' . $locale,
            image: ['url' => 'https://cdn.test/share.jpg', 'width' => 1200, 'height' => 630, 'alt' => 'Our club'],
            // Page-level fields a site default must never pass on to a page.
            locale: 'fr',
            alternateLocales: ['it'],
            ogType: 'article',
            schemas: [['@type' => 'Thing', 'name' => 'Leaked']],
        );
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(SeoSiteProvider::class, true) . "];\n");

        $plain = (string) $this->get('/plain')->getBody();
        $this->assertStringContainsString('<meta property="og:site_name" content="Site en">', $plain);
        $this->assertStringContainsString('<meta property="og:description" content="Board games in en">', $plain);
        $this->assertStringContainsString('<meta property="og:image" content="https://cdn.test/share.jpg">', $plain);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $plain);
        // The defaults' title is the site's name, not the page's: the page's own <title> is.
        $this->assertStringContainsString('<meta property="og:title" content="Plain">', $plain);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $plain);
        $this->assertStringContainsString('<meta property="og:locale" content="en">', $plain);
        $this->assertStringNotContainsString('og:locale:alternate', $plain);
        $plainGraph = $this->graph($plain);
        $this->assertSame(['WebPage'], array_column($plainGraph, '@type'));
        $this->assertSame('Plain', $plainGraph[0]['name']);
        $this->assertSame('en', $plainGraph[0]['inLanguage']);

        $about = (string) $this->get('/about')->getBody();
        $this->assertStringContainsString('<meta property="og:description" content="Who we are">', $about);
        $this->assertStringContainsString('<meta property="og:image" content="https://site.test/img/share.jpg">', $about);

        $this->assertStringContainsString('<meta property="og:title" content="About us">', $about);

        $graph = $this->graph((string) $this->get('/de/')->getBody());
        $this->assertSame('Site de', $graph[0]['name']);
        $this->assertSame('Home', $graph[1]['name']);
        // No owner configured: the WebSite names no publisher.
        $this->assertSame(['WebSite', 'WebPage'], array_column($graph, '@type'));
        $this->assertArrayNotHasKey('publisher', $graph[0]);
    }

    #[RunInSeparateProcess]
    public function test_no_head_tags_under_disallowed_paths(): void
    {
        SeoSiteProvider::$disallow = ['/plain/', '/ab*t'];
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(SeoSiteProvider::class, true) . "];\n");

        // "/plain" is the "/plain/" directory without its slash; "/about" matches the wildcard.
        foreach (['/plain', '/about'] as $path) {
            $html = (string) $this->get($path)->getBody();
            $this->assertStringContainsString('<title>', $html, $path);
            foreach (['name="robots"', 'theme-color', 'og:', 'twitter:', 'ld+json'] as $tag) {
                $this->assertStringNotContainsString($tag, $html, $path . ': ' . $tag);
            }
        }
        $this->assertStringContainsString('twitter:card', (string) $this->get('/inner')->getBody()); // other paths keep theirs
    }

    #[RunInSeparateProcess]
    public function test_allowed_paths_under_a_disallowed_one_keep_their_head_tags(): void
    {
        SeoSiteProvider::$disallow = ['/ab*'];
        SeoSiteProvider::$allow = ['/about'];
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(SeoSiteProvider::class, true) . "];\n");

        $this->assertStringContainsString('twitter:card', (string) $this->get('/about')->getBody());
        $robots = (string) $this->get('/robots.txt')->getBody();
        $this->assertStringContainsString("Allow: /\nAllow: /about\nDisallow: /--component/\n", $robots);
        $this->assertStringContainsString("Disallow: /ab*\n", $robots);
    }

    #[RunInSeparateProcess]
    public function test_works_without_folio(): void
    {
        $this->assertStringContainsString('<meta property="og:site_name" content="Game Club">', (string) $this->get('/plain')->getBody());

        $this->seo("['enabled' => true]");
        $this->assertStringContainsString('<meta property="og:site_name" content="App Name">', (string) $this->get('/plain')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_etag_ignores_the_nonce(): void
    {
        // Each app has its own nonce, as each production request does.
        $first = $this->get('/about');
        $second = $this->get('/about');
        $etag = $first->getHeaderLine('ETag');

        $this->assertNotSame((string) $first->getBody(), (string) $second->getBody());
        $this->assertMatchesRegularExpression('~^W/"[0-9a-f]{40}"$~', $etag);
        $this->assertSame($etag, $second->getHeaderLine('ETag'));
        $this->assertSame('no-cache', $first->getHeaderLine('Cache-Control'));

        foreach ([$etag, 'W/"other", ' . $etag, substr($etag, 2), '*'] as $match) {
            $notModified = $this->get('/about', ['If-None-Match' => $match]);
            $this->assertSame(304, $notModified->getStatusCode(), $match);
            $this->assertSame('', (string) $notModified->getBody());
            $this->assertSame($etag, $notModified->getHeaderLine('ETag'));
        }

        $this->assertSame(200, $this->get('/about', ['If-None-Match' => 'W/"other"'])->getStatusCode());
        $this->assertNotSame($etag, $this->get('/plain')->getHeaderLine('ETag'));

        // Switched off in config/seo.php: no ETag.
        $this->seo("['enabled' => true, 'etag' => false]");
        $this->assertFalse($this->get('/about')->hasHeader('ETag'));
    }

    #[RunInSeparateProcess]
    public function test_no_etag_for_personal_responses(): void
    {
        $sessionCookie = session_name() . '=visitor';
        $cases = [
            'session cookie' => $this->get('/about', ['Cookie' => 'theme=dark; ' . $sessionCookie]),
            'Set-Cookie' => $this->get('/cookie'),
            'private' => $this->get('/private'),
            'no-store' => $this->get('/no-store'),
        ];
        foreach ($cases as $label => $response) {
            $this->assertSame(200, $response->getStatusCode(), $label);
            $this->assertFalse($response->hasHeader('ETag'), $label);
            $this->assertNotSame('no-cache', $response->getHeaderLine('Cache-Control'), $label);
        }
        // Another cookie isn't the session's.
        $this->assertTrue($this->get('/about', ['Cookie' => 'theme=dark'])->hasHeader('ETag'));

        // The session cookie is the one config/auth.php names, not PHP's default.
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn ["
            . "'default_guard' => 'session',"
            . "'guards' => ['session' => ['class' => \\Preflow\\Auth\\SessionGuard::class, 'provider' => 'config']],"
            . "'providers' => ['config' => ['class' => \\Preflow\\Auth\\ConfigUserProvider::class, 'users' => []]],"
            . "'password_hasher' => \\Preflow\\Auth\\NativePasswordHasher::class,"
            . "'session' => ['cookie' => 'club_session', 'paths' => ['/account']],"
            . "];\n");
        $this->assertFalse($this->get('/about', ['Cookie' => 'club_session=visitor'])->hasHeader('ETag'));
        $this->assertTrue($this->get('/about', ['Cookie' => session_name() . '=visitor'])->hasHeader('ETag'));
        unlink($this->dir . '/config/auth.php');

        // A cookie PHP queued natively (a session started during the request) counts too.
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'text/html'], '<html><head></head><body>Hi</body></html>');
            }
        };
        $middleware = fn (array $sent): SeoMiddleware => new SeoMiddleware(
            SeoConfig::fromArray(['enabled' => true]),
            new SeoContext(),
            new PublicPages(),
            new SchemaRegistry(),
            new SeoSite(),
            new NonceGenerator(),
            'https://site.test',
            ['en'],
            'en',
            'App',
            false,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01'),
            sentHeaders: static fn (): array => $sent,
        );
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://site.test/');
        $this->assertTrue($middleware(['X-Powered-By: PHP'])->process($request, $handler)->hasHeader('ETag'));
        $this->assertFalse($middleware(['Set-Cookie: PHPSESSID=abc; path=/'])->process($request, $handler)->hasHeader('ETag'));
    }

    #[RunInSeparateProcess]
    public function test_a_304_repeats_geos_vary_accept(): void
    {
        file_put_contents($this->dir . '/config/geo.php', "<?php\nreturn ['enabled' => true];\n");

        $page = $this->get('/about');
        $this->assertContains('accept', array_map('trim', explode(',', strtolower($page->getHeaderLine('Vary')))));

        // RFC 9110 15.4.5: a 304 sends the Vary the 200 would have.
        $notModified = $this->get('/about', ['If-None-Match' => $page->getHeaderLine('ETag')]);
        $this->assertSame(304, $notModified->getStatusCode());
        $this->assertContains('accept', array_map('trim', explode(',', strtolower($notModified->getHeaderLine('Vary')))));
        $this->assertSame($page->getHeaderLine('ETag'), $notModified->getHeaderLine('ETag'));
    }

    #[RunInSeparateProcess]
    public function test_geo_still_outermost(): void
    {
        file_put_contents($this->dir . '/config/geo.php', "<?php\nreturn ['enabled' => true];\n");

        $markdown = $this->get('/about.md');
        $this->assertSame(200, $markdown->getStatusCode(), (string) $markdown->getBody());
        $this->assertStringStartsWith('text/markdown', $markdown->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('We play games.', (string) $markdown->getBody());
        $this->assertStringNotContainsString('schema.org', (string) $markdown->getBody());

        // The HTML page itself gets both: GEO's alternate link and SEO's tags.
        $html = (string) $this->get('/about')->getBody();
        $this->assertStringContainsString('type="text/markdown"', $html);
        $this->assertStringContainsString('og:title', $html);

        // An internal render's seo() call doesn't reach the page that asked for it.
        $outer = $this->get('/__inner');
        $this->assertSame('yes', $outer->getHeaderLine('X-Inner-Rendered'));
        $this->assertStringNotContainsString('Inner', (string) $outer->getBody());
        $this->assertStringContainsString('<meta property="og:url" content="https://site.test/__inner">', (string) $outer->getBody());
    }
}
