<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Api;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\Api\ApiConfig;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Psr\Http\Message\ResponseInterface;

/** CORS through a booted app: on error responses, and around the page cache. */
final class CorsBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_cors_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        unset($_SESSION);
        // What the page cache logs (no site logger here) goes to a file, not the test's output.
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    /** @param array<string, mixed> $api config/api.php */
    private function site(array $api, ?array $pageCache = null): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'cors-boot-test-key-0123456789abcd','url'=>'https://example.com','locale'=>'en'];\n");
        $this->write('config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data']]];\n");
        $this->write('config/api.php', '<?php return ' . var_export($api, true) . ';');
        if ($pageCache !== null) {
            $this->write('config/page_cache.php', '<?php return ' . var_export($pageCache, true) . ';');
        }
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $headers */
    private function get(Application $app, string $path, array $headers = []): ResponseInterface
    {
        return $app->handle(new ServerRequest('GET', 'https://example.com' . $path, $headers));
    }

    #[RunInSeparateProcess]
    public function test_error_response_carries_cors_headers(): void
    {
        $this->site(['prefixes' => ['/api', '/hooks'], 'cors' => ['origins' => ['https://app.test']]]);
        $app = $this->app();

        // Boot builds ApiConfig from config/api.php, and its prefixes are not public pages.
        $config = $app->container()->get(ApiConfig::class);
        $this->assertSame(['/api', '/hooks'], $config->prefixes);
        $this->assertSame(['https://app.test'], $config->corsOrigins);
        $nonPublic = $app->container()->get(NonPublicPaths::class);
        $this->assertTrue($nonPublic->matches('/api/x'));
        $this->assertTrue($nonPublic->matches('/hooks/x'));

        $response = $this->get($app, '/api/x', ['Origin' => 'https://app.test']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertArrayHasKey('message', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Origin', $response->getHeaderLine('Vary'));

        // Another origin gets the error without CORS headers.
        $other = $this->get($app, '/api/x', ['Origin' => 'https://evil.test']);
        $this->assertSame(404, $other->getStatusCode());
        $this->assertFalse($other->hasHeader('Access-Control-Allow-Origin'));
    }

    #[RunInSeparateProcess]
    public function test_preflight_is_answered_before_the_rest_of_the_pipeline(): void
    {
        $this->site(['cors' => ['origins' => ['https://app.test']]]);
        $app = $this->app();

        $response = $app->handle(new ServerRequest('OPTIONS', 'https://example.com/api/x', [
            'Origin' => 'https://app.test',
            'Access-Control-Request-Method' => 'POST',
        ]));

        // No route answers OPTIONS /api/x: the 204 comes from the CORS middleware.
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, PUT, PATCH, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[RunInSeparateProcess]
    public function test_without_cors_origins_nothing_changes(): void
    {
        $this->site([]);
        $app = $this->app();

        $response = $this->get($app, '/api/x', ['Origin' => 'https://app.test']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertFalse($response->hasHeader('Vary'));
    }

    #[RunInSeparateProcess]
    public function test_page_cache_hit_gets_cors_for_each_origin(): void
    {
        $a = 'https://a.test';
        $b = 'https://b.test';
        $this->site(
            ['cors' => ['origins' => [$a, $b]]],
            ['enabled' => true, 'explain' => true, 'verify' => 'off'],
        );
        $this->write('app/Controllers/PingController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Preflow\Routing\Attributes\Get;
            use Preflow\Routing\Attributes\Route;

            #[Route('/api')]
            final class PingController
            {
                #[Get('/ping')]
                public function ping(): array
                {
                    return ['data' => ['pong' => microtime(true)]];
                }
            }
            PHP);
        $dir = $this->dir;
        spl_autoload_register(static function (string $class) use ($dir): void {
            if ($class === 'App\\Controllers\\PingController') {
                require $dir . '/app/Controllers/PingController.php';
            }
        });

        $app = $this->app();
        // API paths are not public pages, so the page cache passes them by. A site whose API
        // GETs are cached (the case this guards) is stood in for by an empty list: the
        // middleware asks for it on each request.
        $app->container()->instance(NonPublicPaths::class, new NonPublicPaths());

        $first = $this->get($app, '/api/ping', ['Origin' => $a]);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('miss', $first->getHeaderLine('X-Page-Cache'));
        $this->assertSame($a, $first->getHeaderLine('Access-Control-Allow-Origin'));

        $second = $this->get($app, '/api/ping', ['Origin' => $b]);
        $this->assertSame('hit', $second->getHeaderLine('X-Page-Cache'));
        $this->assertSame((string) $first->getBody(), (string) $second->getBody());
        $this->assertSame($b, $second->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame(['Origin'], $second->getHeader('Vary'));

        // The stored copy holds no CORS header, or another origin would get it.
        $config = $app->container()->get(PageCacheConfig::class);
        $key = PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/api/ping'), $config);
        $stored = (new PageStore($this->dir . '/storage/cache/pages', PageStore::releaseKey($this->dir)))->get($key);
        $this->assertNotNull($stored);
        foreach (array_keys($stored->headers) as $name) {
            $this->assertStringStartsNotWith('access-control-', strtolower((string) $name));
            $this->assertNotSame('vary', strtolower((string) $name));
        }
    }
}
