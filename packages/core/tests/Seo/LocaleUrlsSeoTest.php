<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Seo;

require_once __DIR__ . '/../Fixtures/Seo/SeoSiteProvider.php';
require_once __DIR__ . '/../Fixtures/Geo/GeoEntriesProvider.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\Geo\GeoEntriesProvider;
use Preflow\Core\Tests\Fixtures\Seo\SeoSiteProvider;
use Preflow\View\Geo\GeoEntry;
use Psr\Http\Message\ResponseInterface;

/** SEO and GEO with prefix_default and redirect_bare (config/i18n.php). */
final class LocaleUrlsSeoTest extends TestCase
{
    private string $dir;

    private const KEY = 'locale-urls-seo-test-key-0123456789';

    private const PAGE = '<html><head><title>{{ current_locale() }}</title></head><body><main><h1>Welcome</h1></main></body></html>';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/locale_urls_seo_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'App Name','debug'=>0,'engine'=>'twig','key'=>'" . self::KEY . "','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(SeoSiteProvider::class, true) . ', ' . var_export(GeoEntriesProvider::class, true) . "];\n");
        file_put_contents($this->dir . '/config/seo.php', "<?php\nreturn ['enabled' => true, 'name' => 'Game Club'];\n");
        file_put_contents($this->dir . '/config/geo.php', "<?php\nreturn ['enabled' => true, 'name' => 'Game Club', 'summary' => 'Board games, explained.'];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', self::PAGE);
        file_put_contents($this->dir . '/app/pages/about.twig', self::PAGE);

        SeoSiteProvider::reset();
        GeoEntriesProvider::$source = static fn (string $locale): array => [new GeoEntry('About', '/' . $locale . '/about', 'Who we are')];
        GeoEntriesProvider::$site = null;
    }

    protected function tearDown(): void
    {
        SeoSiteProvider::reset();
        GeoEntriesProvider::$source = null;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $options */
    private function i18n(array $options): void
    {
        file_put_contents($this->dir . '/config/i18n.php', '<?php return ' . var_export(
            $options + ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'],
            true,
        ) . ';');
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = []): ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->boot();
        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    /** @return list<string> the JSON-LD graph's node types in $html */
    private function types(string $html): array
    {
        $this->assertSame(1, preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m), $html);

        return array_column(json_decode($m[1], true, flags: JSON_THROW_ON_ERROR)['@graph'], '@type');
    }

    #[RunInSeparateProcess]
    public function test_home_graph_on_prefixed_default_home(): void
    {
        $this->i18n(['prefix_default' => true]);

        foreach (['/en', '/en/', '/de'] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertSame(['WebSite', 'WebPage'], $this->types((string) $response->getBody()), $path);
        }

        // The bare root is served (no redirect_bare) but is not the default locale's home any more.
        $root = $this->get('/');
        $this->assertSame(200, $root->getStatusCode());
        $this->assertSame(['WebPage'], $this->types((string) $root->getBody()));
    }

    #[RunInSeparateProcess]
    public function test_llms_txt_stays_at_the_root_with_prefix_default(): void
    {
        $this->i18n(['prefix_default' => true, 'redirect_bare' => true]);

        $llms = $this->get('/llms.txt');
        $body = (string) $llms->getBody();
        $this->assertSame(200, $llms->getStatusCode(), $body);
        $this->assertStringContainsString('- [About](https://site.test/en/about.md): Who we are', $body);
        $this->assertStringEndsWith("\n- [de](https://site.test/de/llms.txt)\n", $body);
        $this->assertSame(200, $this->get('/de/llms.txt')->getStatusCode());
        // One address for the default locale's file, as the llms.txt convention has it.
        $this->assertSame(404, $this->get('/en/llms.txt')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_bare_redirect_leaves_robots_sitemap_and_llms_alone(): void
    {
        $this->i18n(['prefix_default' => true, 'redirect_bare' => true]);

        foreach (['/robots.txt', '/sitemap.xml', '/llms.txt'] as $path) {
            $response = $this->get($path, ['Accept-Language' => 'de']);
            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertFalse($response->hasHeader('Location'), $path);
        }
        // A page is still redirected.
        $this->assertSame('/de/about', $this->get('/about', ['Accept-Language' => 'de'])->getHeaderLine('Location'));
    }
}
