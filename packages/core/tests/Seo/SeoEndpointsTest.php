<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Seo;

require_once __DIR__ . '/../Fixtures/Seo/SeoSiteProvider.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\Seo\SeoSiteProvider;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\RobotsTxt;
use Preflow\View\Seo\SeoConfig;
use Psr\Http\Message\ResponseInterface;

/** /robots.txt, /sitemap.xml and security.txt (config/seo.php), and nothing at all with SEO off. */
final class SeoEndpointsTest extends TestCase
{
    private string $dir;

    private const KEY = 'seo-endpoints-test-key-0123456789ab';

    private const CONFIG = "['enabled' => true, 'name' => 'Game Club', 'security_txt' => ['contact' => 'security@site.test', 'policy' => 'https://site.test/policy']]";


    private const PAGE = '<html><head><title>Home</title></head><body><main><h1>Welcome</h1><script nonce="{{ csp_nonce() }}">x()</script></main></body></html>';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/seo_endpoints_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'App Name','debug'=>0,'engine'=>'twig','key'=>'" . self::KEY . "','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'fallback'=>'en','url_strategy'=>'prefix'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(SeoSiteProvider::class, true) . "];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', self::PAGE);
        $this->seo(self::CONFIG);

        SeoSiteProvider::reset();
        SeoSiteProvider::$disallow = ['/admin/'];
        SeoSiteProvider::$allow = ['/admin/public/'];
        SeoSiteProvider::$pages = static fn (string $locale): array => $locale === 'de'
            ? [new PublicPage('Start', '/de/', alternates: ['en' => '/', 'de' => '/de/'])]
            : [
                new PublicPage('Home', '/', lastmod: '2026-10-01T10:00:00+00:00', alternates: ['en' => '/', 'de' => '/de/']),
                new PublicPage('About', '/about'),
                new PublicPage('Only in llms.txt', '/llms-only', sitemap: false),
            ];
    }

    protected function tearDown(): void
    {
        SeoSiteProvider::reset();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function seo(?string $config): void
    {
        $path = $this->dir . '/config/seo.php';
        if ($config === null) {
            @unlink($path);
            return;
        }
        file_put_contents($path, "<?php\nreturn {$config};\n");
    }

    private function get(string $path, string $method = 'GET'): ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app->handle((new Psr17Factory())->createServerRequest($method, 'http://localhost' . $path));
    }

    private function assertServed(ResponseInterface $response, string $type, string $path): string
    {
        $body = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode(), $path . ': ' . $body);
        $this->assertSame($type, $response->getHeaderLine('Content-Type'), $path);
        $this->assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'), $path);

        return $body;
    }

    #[RunInSeparateProcess]
    public function test_robots_txt_with_the_sites_disallowed_paths(): void
    {
        $body = $this->assertServed($this->get('/robots.txt'), 'text/plain; charset=utf-8', '/robots.txt');

        $config = SeoConfig::fromArray(['enabled' => true]);
        $this->assertSame(RobotsTxt::render($config, ['/admin/'], 'https://site.test', false, ['/admin/public/']), $body);
        $this->assertStringContainsString("User-agent: *\nAllow: /\nAllow: /admin/public/\n", $body);
        $this->assertStringContainsString("Disallow: /admin/\n", $body);
        $this->assertStringContainsString("Sitemap: https://site.test/sitemap.xml\n", $body);

        // With GEO on, robots.txt points at llms.txt.
        file_put_contents($this->dir . '/config/geo.php', "<?php\nreturn ['enabled' => true];\n");
        $this->assertStringContainsString(
            "# LLM-readable site index: https://site.test/llms.txt\n",
            $this->assertServed($this->get('/robots.txt'), 'text/plain; charset=utf-8', '/robots.txt'),
        );
    }

    #[RunInSeparateProcess]
    public function test_sitemap_lists_every_locales_public_pages(): void
    {
        $body = $this->assertServed($this->get('/sitemap.xml'), 'application/xml; charset=UTF-8', '/sitemap.xml');

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $body);
        $this->assertStringContainsString("<loc>https://site.test/</loc>\n    <lastmod>2026-10-01T10:00:00+00:00</lastmod>", $body);
        $this->assertStringContainsString('<loc>https://site.test/about</loc>', $body);
        $this->assertStringContainsString('<loc>https://site.test/de/</loc>', $body);
        $this->assertStringContainsString('hreflang="x-default" href="https://site.test/"', $body);
        $this->assertStringNotContainsString('llms-only', $body);

        // HEAD answers like GET, without a body.
        $head = $this->get('/sitemap.xml', 'HEAD');
        $this->assertSame(200, $head->getStatusCode());
        $this->assertSame('', (string) $head->getBody());
    }

    #[RunInSeparateProcess]
    public function test_security_txt_at_the_well_known_url(): void
    {
        $body = $this->assertServed($this->get('/.well-known/security.txt'), 'text/plain; charset=utf-8', 'security.txt');

        $this->assertStringStartsWith("Contact: mailto:security@site.test\nExpires: ", $body);
        $this->assertStringContainsString("Preferred-Languages: en, de\n", $body);
        $this->assertStringContainsString("Canonical: https://site.test/.well-known/security.txt\nPolicy: https://site.test/policy\n", $body);

        $redirect = $this->get('/security.txt');
        $this->assertSame(301, $redirect->getStatusCode());
        $this->assertSame('https://site.test/.well-known/security.txt', $redirect->getHeaderLine('Location'));
        $this->assertSame('public, max-age=3600', $redirect->getHeaderLine('Cache-Control'));
    }

    #[RunInSeparateProcess]
    public function test_no_security_txt_without_a_contact(): void
    {
        $this->seo("['enabled' => true]");

        $this->assertSame(404, $this->get('/.well-known/security.txt')->getStatusCode());
        $this->assertSame(404, $this->get('/security.txt')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_robots_and_sitemap_can_be_switched_off(): void
    {
        $this->seo("['enabled' => true, 'robots' => ['enabled' => false], 'sitemap' => false]");

        $this->assertSame(404, $this->get('/robots.txt')->getStatusCode());
        $this->assertSame(404, $this->get('/sitemap.xml')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_off_changes_nothing(): void
    {
        $pages = [];
        foreach ([null, "['enabled' => false, 'name' => 'Game Club', 'security_txt' => ['contact' => 'security@site.test']]"] as $config) {
            $this->seo($config);
            $label = $config === null ? 'no config/seo.php' : 'enabled => false';

            foreach (['/robots.txt', '/sitemap.xml', '/.well-known/security.txt', '/security.txt'] as $path) {
                $this->assertSame(404, $this->get($path)->getStatusCode(), $label . ': ' . $path);
            }

            $page = $this->get('/');
            $html = (string) $page->getBody();
            $this->assertSame(200, $page->getStatusCode(), $label);
            foreach (['og:', 'twitter:', 'name="robots"', 'ld+json'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, $label);
            }
            $this->assertFalse($page->hasHeader('ETag'), $label);
            $this->assertFalse($page->hasHeader('Cache-Control'), $label);
            // Byte for byte the same page, apart from the per-request nonce.
            $pages[] = str_replace($this->nonceIn($html), 'NONCE', $html);
        }
        $this->assertSame($pages[0], $pages[1]);
    }

    private function nonceIn(string $html): string
    {
        $this->assertSame(1, preg_match('/nonce="([^"]+)"/', $html, $m), $html);

        return $m[1];
    }
}
