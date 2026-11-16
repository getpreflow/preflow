<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

require_once __DIR__ . '/../Fixtures/LocaleUrls/PageLookupFixtures.php';

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\Refresher;
use Preflow\Core\PageCache\StoredPage;
use Preflow\Core\PageCache\TagIndex;
use Psr\Http\Message\ResponseInterface;

/** The page cache with prefix_default and redirect_bare: keys carry the prefix, redirects are never stored. */
final class LocaleUrlsPageCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pclocaleurls_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        unset($_SESSION);
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param array<string, mixed> $i18n options over en/de with the prefix strategy */
    private function site(array $i18n): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'page-cache-test-key-0123456789ab','url'=>'https://example.com','locale'=>'en'];\n");
        $this->write('config/page_cache.php', '<?php return ' . var_export(['enabled' => true, 'explain' => true, 'verify' => 'off'], true) . ';');
        $this->write('config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data']]];\n");
        $this->write('config/i18n.php', '<?php return ' . var_export($i18n + ['default' => 'en', 'available' => ['en', 'de'], 'url_strategy' => 'prefix'], true) . ';');
        foreach (['en' => 'Hello', 'de' => 'Hallo'] as $locale => $hi) {
            $this->write("lang/{$locale}/site.php", "<?php return ['hi' => '{$hi}'];");
        }
        $this->write('app/pages/about.twig', "<p>{{ t('site.hi') }} {{ 'now'|date('U.u') }}</p>");
    }

    private function webApp(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->runAfterResponseInHandle();
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $headers */
    private function web(string $path, array $headers = []): ResponseInterface
    {
        return $this->webApp()->handle(new ServerRequest('GET', 'https://example.com' . $path, $headers));
    }

    private function store(): PageStore
    {
        return new PageStore($this->dir . '/storage/cache/pages', PageStore::releaseKey($this->dir));
    }

    private function config(): PageCacheConfig
    {
        return $this->webApp()->container()->get(PageCacheConfig::class);
    }

    /** @param array<string, string> $parts */
    private function key(string $path, array $parts): PageKey
    {
        return PageKey::fromRequest(new ServerRequest('GET', 'https://example.com' . $path), $this->config(), $parts);
    }

    /** @param array<string, string> $parts */
    private function stored(string $path, array $parts): ?StoredPage
    {
        return $this->store()->get($this->key($path, $parts));
    }

    private function pages(): int
    {
        return TagIndex::forStore($this->store(), $this->config())->count();
    }

    #[RunInSeparateProcess]
    public function test_prefixed_default_pages_are_cached_under_their_prefix(): void
    {
        $this->site(['prefix_default' => true, 'redirect_bare' => true]);

        $this->assertSame('miss', $this->web('/en/about')->getHeaderLine('X-Page-Cache'));
        $hit = $this->web('/en/about');
        $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('Hello', (string) $hit->getBody());
        $german = $this->web('/de/about');
        $this->assertSame('miss', $german->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('Hallo', (string) $german->getBody());

        $this->assertStringContainsString('Hello', (string) $this->stored('/en/about', ['locale' => 'en'])?->body);
        $this->assertStringContainsString('Hallo', (string) $this->stored('/de/about', ['locale' => 'de'])?->body);
        $this->assertNotSame($this->key('/en/about', ['locale' => 'en'])->id(), $this->key('/de/about', ['locale' => 'de'])->id());
        $this->assertNull($this->stored('/about', ['locale' => 'en']), 'nothing under the bare path');
        $this->assertSame(2, $this->pages());
    }

    #[RunInSeparateProcess]
    public function test_bare_redirect_is_never_cached(): void
    {
        $this->site(['prefix_default' => true, 'redirect_bare' => true]);

        foreach ([['Accept-Language' => 'de'], []] as $headers) {
            $response = $this->web('/about', $headers);
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('', $response->getHeaderLine('X-Page-Cache'), 'answered before the page cache');
        }

        $this->assertNull($this->stored('/about', ['locale' => 'en']));
        $this->assertNull($this->stored('/about', ['locale' => 'de']));
        $this->assertSame(0, $this->pages());
    }

    #[RunInSeparateProcess]
    public function test_rebuild_of_a_prefixed_default_page_stores_under_its_key(): void
    {
        $this->site(['prefix_default' => true, 'redirect_bare' => true]);
        $this->web('/en/about');
        $old = (string) $this->stored('/en/about', ['locale' => 'en'])?->body;
        $this->assertNotSame('', $old);

        // The rebuild is an internal render of '/en/about': rendered, never bare-redirected.
        $refresher = $this->webApp()->container()->get(Refresher::class);
        $this->assertSame(1, $refresher->refreshAll());
        $this->assertSame(1, $refresher->work(50.0));

        $rebuilt = (string) $this->stored('/en/about', ['locale' => 'en'])?->body;
        $this->assertStringContainsString('Hello', $rebuilt);
        $this->assertNotSame($old, $rebuilt);
        $this->assertSame(1, $this->pages(), 'no page under another key');
        $this->assertSame('hit', $this->web('/en/about')->getHeaderLine('X-Page-Cache'));
    }

    #[RunInSeparateProcess]
    public function test_bare_default_page_cached_still_redirects_other_languages(): void
    {
        $this->site(['redirect_bare' => true]);

        // An English visitor's bare page is served and cached; the locale middleware adds Vary outside the cache.
        $first = $this->web('/about', ['Accept-Language' => 'en']);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('miss', $first->getHeaderLine('X-Page-Cache'));
        $hit = $this->web('/about', ['Accept-Language' => 'en']);
        $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('accept-language', strtolower($hit->getHeaderLine('Vary')));

        // A German visitor still gets the redirect: the locale middleware answers before the page cache.
        $german = $this->web('/about', ['Accept-Language' => 'de']);
        $this->assertSame(302, $german->getStatusCode());
        $this->assertSame('/de/about', $german->getHeaderLine('Location'));
        $this->assertSame('', $german->getHeaderLine('X-Page-Cache'));
    }

    #[RunInSeparateProcess]
    public function test_a_page_cache_hit_builds_no_page_lookup(): void
    {
        $this->site(['prefix_default' => true, 'redirect_bare' => true]);
        \Preflow\Core\Tests\Fixtures\LocaleUrls\PageLookupProvider::$lookups = [
            \Preflow\Core\Routing\PageExists::class => ['/about'],
            \Preflow\Routing\Redirect\PageExists::class => ['/blog'],
        ];
        $this->write('config/providers.php', "<?php\nreturn [" . var_export(\Preflow\Core\Tests\Fixtures\LocaleUrls\PageLookupProvider::class, true) . "];\n");

        $this->assertSame('miss', $this->web('/en/about')->getHeaderLine('X-Page-Cache'));
        \Preflow\Core\Tests\Fixtures\LocaleUrls\PageLookupProvider::$built = 0;
        $this->assertSame('hit', $this->web('/en/about')->getHeaderLine('X-Page-Cache'));
        $this->assertSame(0, \Preflow\Core\Tests\Fixtures\LocaleUrls\PageLookupProvider::$built, 'neither the boot nor the hit builds a lookup');
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}
