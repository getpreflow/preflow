<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageCacheMiddleware;
use Preflow\Testing\WebApplication;
use Preflow\Testing\ChecksFrameBalance;

final class WebApplicationTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_webapp_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'web-application-test-key-0123456','url'=>'https://example.com'];\n");
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true];\n");
        file_put_contents($this->dir . '/app/pages/page.twig', '<p>{{ request.query.who|default("") }}</p>');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_boots_for_the_web_and_verifies_every_store(): void
    {
        $_SESSION = ['left' => 'by an earlier test'];
        $app = WebApplication::boot($this->dir);
        $this->assertFalse(isset($_SESSION), 'a leftover $_SESSION outside an active session is unset');
        $this->assertTrue($app->container()->bound(PageCacheMiddleware::class), 'the cache serves: web mode');
        $this->assertSame(PageCacheConfig::VERIFY_ALWAYS, $app->container()->get(PageCacheConfig::class)->verify);

        $response = $app->handle(new ServerRequest('GET', 'https://example.com/page'));
        $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'));
        $this->assertSame('hit', WebApplication::boot($this->dir)->handle(new ServerRequest('GET', 'https://example.com/page'))->getHeaderLine('X-Page-Cache'));
    }

    #[RunInSeparateProcess]
    public function test_assert_page_cacheable_passes_for_a_page_every_visitor_gets_alike(): void
    {
        WebApplication::assertPageCacheable(WebApplication::boot($this->dir), '/page');

        $this->assertNull(WebApplication::boot($this->dir)->handle(new ServerRequest('GET', 'https://example.com/page'))->getHeaderLine('X-Page-Cache-Skip') ?: null);
    }

    #[RunInSeparateProcess]
    public function test_assert_page_cacheable_fails_with_reason(): void
    {
        file_put_contents($this->dir . '/app/pages/random.twig', '<p>{{ random(1000000000) }}</p>');

        try {
            WebApplication::assertPageCacheable(WebApplication::boot($this->dir), '/random');
            $this->fail('A page that differs per visitor must fail the assertion.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('/random', $e->getMessage());
            $this->assertStringContainsString('differs', $e->getMessage(), 'the reason');
            $this->assertStringContainsString('live block', $e->getMessage(), 'the hint');
        }
    }

    #[RunInSeparateProcess]
    public function test_assert_page_cacheable_says_when_the_cache_is_off(): void
    {
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => false];\n");

        try {
            WebApplication::assertPageCacheable(WebApplication::boot($this->dir), '/page');
            $this->fail('With the cache off the assertion must fail.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('The page cache is off for this app', $e->getMessage());
        }
    }
}
