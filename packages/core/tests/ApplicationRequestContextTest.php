<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\RequestContext;

final class ApplicationRequestContextTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_reqctx_test_' . uniqid();
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/config');
        file_put_contents($this->tmpDir . '/config/app.php', '<?php return ["debug" => 0];');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/config/app.php');
        @rmdir($this->tmpDir . '/config');
        @rmdir($this->tmpDir);
    }

    public function test_handle_registers_request_context_in_container(): void
    {
        $app = Application::create($this->tmpDir);
        $app->boot();

        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $request = $factory->createServerRequest('GET', '/blog/my-post');

        // Since 0.81 the container's RequestContext is the request of the render frame current
        // when it is asked for: read it while the request runs.
        $context = null;
        $app->onRequest(static function () use ($app, &$context): void {
            $context = $app->container()->get(RequestContext::class);
        });

        try {
            $app->handle($request);
        } catch (\Throwable) {
            // Router may throw (no routes configured) — that's fine,
            // RequestContext should be registered before routing
        }

        $this->assertInstanceOf(RequestContext::class, $context);
        $this->assertSame('/blog/my-post', $context->path);
        $this->assertSame('GET', $context->method);
        $this->assertNull($app->container()->get(RequestContext::class), 'no request outside it');
    }
}
