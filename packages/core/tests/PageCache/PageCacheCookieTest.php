<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\StoredPage;
use Preflow\Core\Tests\PageCache\Fixtures\CookieRoutes;
use Preflow\Testing\ChecksFrameBalance;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/Fixtures/CookieRoutes.php';

/**
 * Since 0.81.1 a site's queued cookies reach the response without auth or a session, so the page
 * cache never stores a page whose render queued one: on the response it receives, or still in the
 * frame's queue (middleware inside the cache queued it after the route; verify's second render; a
 * rebuild), whose cookies a later visitor would otherwise never get.
 */
final class PageCacheCookieTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pccookie_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        unset($_SESSION);
        CookieRoutes::$mode = '';
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        CookieRoutes::$mode = '';
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_a_page_that_sets_a_cookie_is_not_stored(): void
    {
        $this->site();
        CookieRoutes::$mode = 'before';

        $response = $this->web('/x');

        $this->assertSame(['consent=1; Path=/'], $response->getHeader('Set-Cookie'));
        $this->assertSame('set-cookie', $response->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertNull($this->stored('/x'));
    }

    #[RunInSeparateProcess]
    public function test_a_cookie_queued_by_middleware_after_the_route_keeps_the_page_out(): void
    {
        $this->site();
        CookieRoutes::$mode = 'after';

        $response = $this->web('/x');

        $this->assertSame(['consent=1; Path=/'], $response->getHeader('Set-Cookie'), 'the visitor gets it');
        $this->assertSame('set-cookie', $response->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertNull($this->stored('/x'), 'no later visitor is served the page without it');
    }

    #[RunInSeparateProcess]
    public function test_verify_refuses_a_page_that_sets_a_cookie_for_a_visitor_without_one(): void
    {
        $this->site(['verify' => 'always']);
        CookieRoutes::$mode = 'missing';

        $response = $this->web('/x', ['Cookie' => 'consent=1']);

        $this->assertSame([], $response->getHeader('Set-Cookie'), 'this visitor has it');
        $this->assertNull($this->stored('/x'), 'the second render, without the cookie, would set it');
    }

    #[RunInSeparateProcess]
    public function test_a_rebuild_that_queues_a_cookie_is_not_stored(): void
    {
        $this->site();
        CookieRoutes::$mode = 'missing';
        $this->web('/x', ['Cookie' => 'consent=1']);
        $this->assertNotNull($this->stored('/x'), 'a visitor with the cookie: nothing queued, stored');
        $this->store()->markStale(PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/x'), $this->config())->id());

        $stale = $this->web('/x', ['Cookie' => 'consent=1']);

        $this->assertSame('stale', $stale->getHeaderLine('X-Page-Cache'), 'served once more while it is rebuilt');
        $this->assertNull($this->stored('/x'), 'the rebuild (a visitor without the cookie) queued it: not stored');
    }

    /** @param array<string, mixed> $cache config/page_cache.php */
    private function site(array $cache = []): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'page-cache-test-key-0123456789ab','url'=>'https://example.com','locale'=>'en'];\n");
        $this->write('config/page_cache.php', '<?php return ' . var_export($cache + ['enabled' => true, 'explain' => true, 'verify' => 'off'], true) . ';');
        $this->write('config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data']]];\n");
        $this->write('config/middleware.php', '<?php return [' . var_export(CookieRoutes::class, true) . '];');
        $this->write('app/pages/x.twig', '<html><head><title>X</title></head><body><p>x</p></body></html>');
    }

    /** @param array<string, string> $headers */
    private function web(string $path, array $headers = []): ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->runAfterResponseInHandle();
        $app->boot();
        $request = new ServerRequest('GET', 'https://example.com' . $path, $headers);
        if (isset($headers['Cookie'])) {
            parse_str(str_replace('; ', '&', $headers['Cookie']), $cookies);
            $request = $request->withCookieParams($cookies);
        }

        return $app->handle($request);
    }

    private function store(): PageStore
    {
        return new PageStore($this->dir . '/storage/cache/pages', PageStore::releaseKey($this->dir));
    }

    private function config(): PageCacheConfig
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        return $app->container()->get(PageCacheConfig::class);
    }

    private function stored(string $path): ?StoredPage
    {
        return $this->store()->get(PageKey::fromRequest(new ServerRequest('GET', 'https://example.com' . $path), $this->config()));
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}
