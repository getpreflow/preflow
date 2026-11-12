<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Application::onRequest(): hooks see every handled request first, raw; onProvidersBooted():
 * hooks run once every provider booted; runningInConsole() and its override.
 */
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
        @unlink($this->dir . '/config/providers.php');
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

    public function test_providers_booted_hooks_run_once_after_every_provider_booted_in_order(): void
    {
        file_put_contents($this->dir . '/config/providers.php', '<?php return [' . HookingProvider::class . '::class, ' . LaterProvider::class . '::class];');
        HookingProvider::$seen = [];
        LaterProvider::$booted = false;
        $app = Application::create($this->dir);
        $app->boot();

        // The hooks saw what the provider booted after theirs set up.
        $this->assertSame(['first: later booted', 'second'], HookingProvider::$seen);

        $app->onProvidersBooted(static function (): void {
            HookingProvider::$seen[] = 'after boot: at once';
        });
        $this->assertSame(['first: later booted', 'second', 'after boot: at once'], HookingProvider::$seen);
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

final class HookingProvider extends \Preflow\Core\Container\ServiceProvider
{
    /** @var list<string> */
    public static array $seen = [];

    public function register(\Preflow\Core\Container\Container $container): void {}

    public function boot(\Preflow\Core\Container\Container $container): void
    {
        $app = $container->get(Application::class);
        $app->onProvidersBooted(static function () use ($container): void {
            self::$seen[] = 'first: ' . (LaterProvider::$booted ? 'later booted' : 'later not booted');
        });
        $app->onProvidersBooted(static function (): void {
            self::$seen[] = 'second';
        });
    }
}

final class LaterProvider extends \Preflow\Core\Container\ServiceProvider
{
    public static bool $booted = false;

    public function register(\Preflow\Core\Container\Container $container): void {}

    public function boot(\Preflow\Core\Container\Container $container): void
    {
        self::$booted = true;
    }
}
