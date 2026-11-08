<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ServerRequestInterface;

/** Application::onRequest(): hooks see every handled request first, raw; runningInConsole() and its override. */
final class ApplicationRequestHookTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_hook_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', '<?php return ["debug" => 0];');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/app.php');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_hooks_run_in_order_with_the_raw_request_before_routing(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $seen = [];
        $app->onRequest(static function (ServerRequestInterface $request) use (&$seen): void {
            $seen[] = 'first ' . $request->getUri()->getPath() . ' ' . count($request->getAttributes());
        });
        $app->onRequest(static function (ServerRequestInterface $request) use (&$seen): void {
            $seen[] = 'second ' . $request->getUri()->getPath();
        });

        $response = $app->handle((new Psr17Factory())->createServerRequest('GET', '/de/no-such-page'));
        $app->handle((new Psr17Factory())->createServerRequest('GET', '/other'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['first /de/no-such-page 0', 'second /de/no-such-page', 'first /other 0', 'second /other'], $seen);
    }

    public function test_a_throwing_hook_fails_the_request(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $app->onRequest(static function (): void {
            throw new \RuntimeException('setup failed');
        });

        $this->expectExceptionMessage('setup failed');
        $app->handle((new Psr17Factory())->createServerRequest('GET', '/'));
    }

    public function test_running_in_console_follows_the_sapi_unless_set(): void
    {
        $app = Application::create($this->dir);
        $this->assertSame(in_array(PHP_SAPI, ['cli', 'phpdbg'], true), $app->runningInConsole());

        $app->setRunningInConsole(false);
        $this->assertFalse($app->runningInConsole());
        $app->setRunningInConsole(true);
        $this->assertTrue($app->runningInConsole());
    }
}
