<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Checks;

require_once __DIR__ . '/CheckFixtures.php';

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\Checks\SiteEtag;
use Preflow\View\Audit\Checks\SiteIcons;
use Preflow\View\Audit\Checks\SiteLlms;
use Preflow\View\Audit\Checks\SiteNotFound;
use Preflow\View\Audit\Checks\SiteOwner;
use Preflow\View\Audit\Checks\SiteRobots;
use Preflow\View\Audit\Checks\SiteShareImage;
use Preflow\View\Audit\Checks\SiteSitemap;
use Preflow\View\Audit\Checks\Urls;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Geo\GeoConfig;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;
use Preflow\View\Seo\SchemaRegistry;
use Preflow\View\Seo\SeoConfig;
use Preflow\View\Seo\SeoSite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The built-in site checks, each against a small stand-in app. */
final class SiteChecksTest extends TestCase
{
    use CheckFixtures;

    /** @var array<string, ResponseInterface|\Closure(ServerRequestInterface): ResponseInterface> path => answer */
    private array $routes = [];

    /** @var list<string> paths rendered, in order */
    private array $rendered = [];

    private string $public;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir() . '/audit_site_' . bin2hex(random_bytes(4));
        @mkdir($this->public, 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->public, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->public);
    }

    // robots.txt

    public function test_robots_missing_when_seo_is_on_and_nothing_answers(): void
    {
        $check = new SiteRobots($this->seo(), $this->public);

        $this->assertSame('robots.missing', $check->id());
        $this->assertSame(['robots.missing|warning||'], $this->summary($check->check($this->renderer())));
    }

    public function test_robots_answered_by_the_app_or_a_public_file_is_fine(): void
    {
        $this->routes['/robots.txt'] = new Response(200, ['Content-Type' => 'text/plain'], "User-agent: *\nAllow: /\n");
        $this->assertSame([], (new SiteRobots($this->seo(), $this->public))->check($this->renderer()));

        $this->routes = [];
        file_put_contents($this->public . '/robots.txt', "User-agent: *\n");
        $this->assertSame([], (new SiteRobots($this->seo(), $this->public))->check($this->renderer()));
    }

    // sitemap.xml

    public function test_sitemap_missing(): void
    {
        $check = new SiteSitemap($this->seo(), new PublicPages(), $this->public);

        $this->assertSame(['sitemap.missing|warning|https://site.test/sitemap.xml|'], $this->summary($check->check($this->renderer())));
    }

    public function test_sitemap_invalid_xml_or_not_a_sitemap(): void
    {
        $this->routes['/sitemap.xml'] = new Response(200, ['Content-Type' => 'application/xml'], '<urlset><url><loc>https://site.test/</loc></url>');
        $check = new SiteSitemap($this->seo(), new PublicPages(), $this->public);
        $this->assertSame(['sitemap.invalid|critical|https://site.test/sitemap.xml|'], $this->summary($check->check($this->renderer())));

        $this->routes['/sitemap.xml'] = new Response(200, ['Content-Type' => 'text/html'], '<html><body>Sitemap</body></html>');
        $this->assertSame(['sitemap.invalid|critical|https://site.test/sitemap.xml|'], $this->summary($check->check($this->renderer())));
    }

    public function test_sitemap_urls_that_do_not_answer_200_are_reported_once_each(): void
    {
        $this->routes['/sitemap.xml'] = new Response(200, ['Content-Type' => 'application/xml'], $this->urlset([
            'https://site.test/', 'https://site.test/gone', 'https://site.test/de/alt', 'https://site.test/moved',
            'https://site.test/gone', 'https://site.test/fine', 'https://elsewhere.test/x',
        ]));
        $this->routes['/'] = new Response(500, [], 'error');
        $this->routes['/fine'] = new Response(200, ['Content-Type' => 'text/html'], '<html><body>ok</body></html>');
        $this->routes['/moved'] = new Response(301, ['Location' => '/fine']);
        $pages = new PublicPages();
        $pages->source(static fn (string $locale): array => $locale === 'en' ? [new PublicPage('Home', '/')] : []);

        $findings = (new SiteSitemap($this->seo(), $pages, $this->public))->check($this->renderer());

        $this->assertSame([
            'sitemap.non200|critical|https://site.test/gone|en',
            'sitemap.non200|critical|https://site.test/de/alt|de',
            'sitemap.non200|critical|https://site.test/moved|en',
        ], $this->summary($findings));
        $this->assertStringContainsString('404', $findings[0]->message);
        $this->assertStringContainsString('301', $findings[2]->message);
        $this->assertNotContains('/', array_slice($this->rendered, 1), 'a public page has its own status finding; not rendered again');
        $this->assertNotContains('/x', $this->rendered);
    }

    public function test_a_sitemap_index_and_a_public_sitemap_file_are_read(): void
    {
        file_put_contents($this->public . '/sitemap.xml', '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<sitemap><loc>https://site.test/sitemap-pages.xml</loc></sitemap><sitemap><loc>https://site.test/sitemap-broken.xml</loc></sitemap></sitemapindex>');
        $this->routes['/sitemap-pages.xml'] = new Response(200, ['Content-Type' => 'application/xml'], $this->urlset(['https://site.test/gone']));
        $this->routes['/sitemap-broken.xml'] = new Response(200, ['Content-Type' => 'application/xml'], 'not xml');

        $findings = (new SiteSitemap($this->seo(), new PublicPages(), $this->public))->check($this->renderer());

        $this->assertSame([
            'sitemap.invalid|critical|https://site.test/sitemap-broken.xml|',
            'sitemap.non200|critical|https://site.test/gone|en',
        ], $this->summary($findings));
    }

    public function test_a_valid_sitemap_of_good_pages_is_fine(): void
    {
        $this->routes['/sitemap.xml'] = new Response(200, ['Content-Type' => 'application/xml'], $this->urlset(['https://site.test/fine']));
        $this->routes['/fine'] = new Response(200, ['Content-Type' => 'text/html'], 'ok');

        $this->assertSame([], (new SiteSitemap($this->seo(), new PublicPages(), $this->public))->check($this->renderer()));
    }

    // llms.txt

    public function test_llms_missing_per_locale_when_geo_is_on(): void
    {
        $this->routes['/llms.txt'] = new Response(200, ['Content-Type' => 'text/plain'], '# Site');
        $check = new SiteLlms(new GeoConfig(enabled: true), $this->public);

        $this->assertSame('llms.missing', $check->id());
        $this->assertSame(['llms.missing|warning|https://site.test/de/llms.txt|de'], $this->summary($check->check($this->renderer())));
        $this->assertSame(['/llms.txt', '/de/llms.txt'], $this->rendered);

        file_put_contents($this->public . '/llms.txt', '# Site');
        @mkdir($this->public . '/de');
        file_put_contents($this->public . '/de/llms.txt', '# Site');
        $this->routes = [];
        $this->assertSame([], $check->check($this->renderer()));
    }

    // SEO and GEO off

    public function test_with_seo_and_geo_off_their_checks_find_nothing_and_render_nothing(): void
    {
        $off = new SeoConfig();
        $checks = [
            new SiteRobots($off, $this->public),
            new SiteSitemap($off, new PublicPages(), $this->public),
            new SiteLlms(new GeoConfig(), $this->public),
            new SiteShareImage($off, new SeoSite()),
            new SiteOwner($off, new SchemaRegistry()),
            new SiteEtag($off),
        ];
        foreach ($checks as $check) {
            $this->assertSame([], $check->check($this->renderer()), $check->id());
        }
        $this->assertSame([], $this->rendered);
    }

    // the 404 page

    public function test_a_made_up_path_must_answer_404_with_a_link_home(): void
    {
        $this->routes['*'] = fn (ServerRequestInterface $r): ResponseInterface => new Response(200, ['Content-Type' => 'text/html'], '<html><body>Soft</body></html>');
        $check = new SiteNotFound();

        $findings = $check->check($this->renderer());

        $this->assertSame('notfound', $check->id());
        $this->assertCount(1, $findings);
        $this->assertSame('notfound.status', $findings[0]->check);
        $this->assertSame('critical', $findings[0]->severity->value);
        $this->assertStringContainsString('200', $findings[0]->message);
        $this->assertStringStartsWith('https://site.test/', $findings[0]->url);
        $this->assertSame([Urls::path($findings[0]->url)], $this->rendered);
        $this->assertNotSame('https://site.test/', $findings[0]->url);
    }

    public function test_a_404_page_without_a_link_home(): void
    {
        $this->routes['*'] = fn (): ResponseInterface => new Response(404, ['Content-Type' => 'text/html'], '<html><body><a href="/about">About</a><a href="https://elsewhere.test/">x</a></body></html>');

        $findings = (new SiteNotFound())->check($this->renderer());

        $this->assertSame(['notfound.no-home-link'], array_map(static fn ($f): string => $f->check, $findings));
        $this->assertSame('warning', $findings[0]->severity->value);
        $this->assertStringStartsWith('https://site.test/', $findings[0]->url);
    }

    public function test_a_404_page_linking_home_in_any_spelling_is_fine(): void
    {
        foreach (['/', 'https://site.test', '/de/', '/de'] as $href) {
            $this->routes['*'] = fn (): ResponseInterface => new Response(404, ['Content-Type' => 'text/html'], "<html><body><a href=\"{$href}\">Home</a></body></html>");
            $this->assertSame([], (new SiteNotFound())->check($this->renderer()), $href);
        }
    }

    // icons

    public function test_icons_missing_on_the_home_page(): void
    {
        $this->home('<link rel="icon" href="/icon.svg" type="image/svg+xml"><link rel="icon" href="/small.png" sizes="32x32">');
        file_put_contents($this->public . '/small.png', self::png(32));
        $check = new SiteIcons($this->public);

        $this->assertSame('icons', $check->id());
        $this->assertSame([
            'favicon.raster-missing|warning||',
            'apple-touch.missing|notice||',
            'manifest.missing|notice||',
        ], $this->summary($check->check($this->renderer())));
    }

    public function test_a_large_enough_raster_icon_by_sizes_or_by_its_file(): void
    {
        $this->home('<link rel="icon" href="/icon-96.png" sizes="48x48 96x96"><link rel="apple-touch-icon" href="/apple.png"><link rel="manifest" href="/site.webmanifest">');
        $this->assertSame([], (new SiteIcons($this->public))->check($this->renderer()));

        $this->home('<link rel="shortcut icon" href="/img/fav.png"><link rel="apple-touch-icon" href="/apple.png"><link rel="manifest" href="/m.json">');
        @mkdir($this->public . '/img');
        file_put_contents($this->public . '/img/fav.png', self::png(64));
        $this->assertSame([], (new SiteIcons($this->public))->check($this->renderer()));
    }

    public function test_a_raster_icon_the_app_serves_is_measured_from_its_response(): void
    {
        $this->home('<link rel="icon" href="/media/icon.png">');
        $this->routes['/media/icon.png'] = new Response(200, ['Content-Type' => 'image/png'], self::png(16));

        $this->assertContains('favicon.raster-missing|warning||', $this->summary((new SiteIcons($this->public))->check($this->renderer())));

        $this->routes['/media/icon.png'] = new Response(200, ['Content-Type' => 'image/png'], self::png(48));
        $this->assertNotContains('favicon.raster-missing|warning||', $this->summary((new SiteIcons($this->public))->check($this->renderer())));
    }

    public function test_a_favicon_ico_file_or_answer_or_an_apple_touch_file_count(): void
    {
        $this->home('');
        file_put_contents($this->public . '/favicon.ico', 'ico');
        file_put_contents($this->public . '/apple-touch-icon.png', self::png(180));
        $this->assertSame(['manifest.missing|notice||'], $this->summary((new SiteIcons($this->public))->check($this->renderer())));

        unlink($this->public . '/favicon.ico');
        $this->routes['/favicon.ico'] = new Response(200, ['Content-Type' => 'image/x-icon'], 'ico');
        $this->assertSame(['manifest.missing|notice||'], $this->summary((new SiteIcons($this->public))->check($this->renderer())));
    }

    // ETag

    public function test_etag_stable_and_answered_with_304_is_fine(): void
    {
        $this->routes['/'] = function (ServerRequestInterface $r): ResponseInterface {
            return $r->getHeaderLine('If-None-Match') === 'W/"2-a"'
                ? new Response(304, ['ETag' => 'W/"2-a"'])
                : new Response(200, ['Content-Type' => 'text/html', 'ETag' => 'W/"2-a"'], '<html><body>Home</body></html>');
        };
        $check = new SiteEtag($this->seo());

        $this->assertSame('etag', $check->id());
        $this->assertSame([], $check->check($this->renderer()));
        $this->assertSame(['/', '/', '/'], $this->rendered);
    }

    public function test_etag_that_changes_between_renders(): void
    {
        $n = 0;
        $this->routes['/'] = function () use (&$n): ResponseInterface {
            $n++;

            return new Response(200, ['Content-Type' => 'text/html', 'ETag' => "W/\"2-{$n}\""], "<html><body>{$n}</body></html>");
        };

        $this->assertSame(['etag.unstable|notice|https://site.test/|'], $this->summary((new SiteEtag($this->seo()))->check($this->renderer())));
        $this->assertSame(2, $n, 'no conditional render once the ETags differ');
    }

    public function test_etag_not_answered_with_304(): void
    {
        $this->routes['/'] = new Response(200, ['Content-Type' => 'text/html', 'ETag' => 'W/"2-a"'], '<html><body>Home</body></html>');

        $this->assertSame(['etag.no-304|notice|https://site.test/|'], $this->summary((new SiteEtag($this->seo()))->check($this->renderer())));
    }

    public function test_a_home_page_setting_a_cookie_gets_no_etag_and_says_why(): void
    {
        $this->routes['/'] = new Response(200, ['Content-Type' => 'text/html', 'Set-Cookie' => 'locale=en; Path=/; HttpOnly'], '<html><body>Home</body></html>');

        $findings = (new SiteEtag($this->seo()))->check($this->renderer());

        $this->assertSame(['etag.cookie|notice|https://site.test/|'], $this->summary($findings));
        $this->assertStringContainsString('locale', $findings[0]->message);
        $this->assertSame(['/'], $this->rendered);
    }

    public function test_no_etag_without_a_cookie_or_with_etags_off_finds_nothing(): void
    {
        $this->routes['/'] = new Response(200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private'], '<html><body>Home</body></html>');
        $this->assertSame([], (new SiteEtag($this->seo()))->check($this->renderer()));

        $this->routes['/'] = new Response(200, ['Content-Type' => 'text/html', 'Set-Cookie' => 'locale=en'], '<html><body>Home</body></html>');
        $this->assertSame([], (new SiteEtag(new SeoConfig(enabled: true, etag: false)))->check($this->renderer()));

        $this->routes['/'] = new Response(500, [], 'error');
        $this->assertSame([], (new SiteEtag($this->seo()))->check($this->renderer()));
    }

    // share image and owner

    public function test_share_image_missing_for_every_locale_or_some(): void
    {
        $site = new SeoSite();
        $this->assertSame(['share-image.missing|notice||'], $this->summary((new SiteShareImage($this->seo(), $site))->check($this->renderer())));

        $site->defaults(static fn (string $locale): PageMeta => new PageMeta(image: $locale === 'en' ? ['url' => '/share.jpg'] : null));
        $this->assertSame(['share-image.missing|notice||de'], $this->summary((new SiteShareImage($this->seo(), $site))->check($this->renderer())));

        $site->defaults(static fn (): PageMeta => new PageMeta(image: ['url' => '/share.jpg']));
        $this->assertSame([], (new SiteShareImage($this->seo(), $site))->check($this->renderer()));
    }

    public function test_owner_missing_unless_configured_or_registered(): void
    {
        $schemas = new SchemaRegistry();
        $this->assertSame(['owner.missing|notice||'], $this->summary((new SiteOwner($this->seo(), $schemas))->check($this->renderer())));

        $this->assertSame([], (new SiteOwner(SeoConfig::fromArray(['enabled' => true, 'owner' => ['name' => 'Club']]), $schemas))->check($this->renderer()));

        $schemas->owner(static fn (string $locale): ?array => $locale === 'de' ? ['name' => 'Verein'] : null);
        $this->assertSame(['owner.missing|notice||en'], $this->summary((new SiteOwner($this->seo(), $schemas))->check($this->renderer())));
    }

    // helpers

    private function seo(): SeoConfig
    {
        return new SeoConfig(enabled: true);
    }

    private function home(string $head): void
    {
        $this->routes['/'] = new Response(200, ['Content-Type' => 'text/html'], $this->html('<title>Home</title>' . $head));
    }

    /** @param list<string> $urls */
    private function urlset(array $urls): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . implode('', array_map(static fn (string $u): string => "<url><loc>{$u}</loc></url>", $urls)) . '</urlset>';
    }

    /** A square PNG of $size pixels. */
    private static function png(int $size): string
    {
        $image = imagecreatetruecolor($size, $size);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function renderer(): PageRenderer
    {
        $this->rendered = [];

        return new PageRenderer(function (ServerRequestInterface $request): ResponseInterface {
            $path = $request->getUri()->getPath();
            $this->rendered[] = $path;
            $answer = $this->routes[$path] ?? $this->routes['*'] ?? new Response(404, ['Content-Type' => 'text/html'], '<html><body>Not found</body></html>');

            return $answer instanceof \Closure ? $answer($request) : $answer;
        }, 'https://site.test', null, null, ['en', 'de'], 'en');
    }
}
