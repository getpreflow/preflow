<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageCacheMiddleware;
use Preflow\Testing\WebApplication;

final class WebApplicationTest extends TestCase
{
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
        $app = WebApplication::boot($this->dir);
        $this->assertTrue($app->container()->bound(PageCacheMiddleware::class), 'the cache serves: web mode');
        $this->assertSame(PageCacheConfig::VERIFY_ALWAYS, $app->container()->get(PageCacheConfig::class)->verify);

        $response = $app->handle(new ServerRequest('GET', 'https://example.com/page'));
        $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'));
        $this->assertSame('hit', WebApplication::boot($this->dir)->handle(new ServerRequest('GET', 'https://example.com/page'))->getHeaderLine('X-Page-Cache'));
    }
}
