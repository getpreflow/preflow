<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\Tests\Integration\Fixtures\CustomTransportProvider;
use Preflow\Notify\TransportFactory;

final class BootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/notifyboot_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents(
            $this->dir . '/config/app.php',
            "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n",
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/config/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_boot_binds_notifier_when_config_exists(): void
    {
        $this->writeNotifyConfig([
            'transports' => ['memory' => ['driver' => 'memory']],
            'channels' => ['email' => ['transport' => 'memory']],
        ]);

        $container = $this->boot();

        $sent = $container->get(Notifier::class)->send(new Email('a@b.test', 'S', 'T'));

        $this->assertSame('memory', $sent->transport);
        $this->assertCount(1, $container->get(TransportFactory::class)->get('memory')->sent());
    }

    public function test_boot_skips_without_config(): void
    {
        $container = $this->boot();

        // Container::has() is true for any existing class, so assert that no
        // instance was bound: autowiring a Notifier without config must fail.
        $this->expectException(\Throwable::class);
        $container->get(Notifier::class);
    }

    public function test_provider_can_extend_factory_in_boot(): void
    {
        $this->writeNotifyConfig([
            'transports' => ['sdk' => ['driver' => 'custom']],
            'channels' => ['email' => ['transport' => 'sdk']],
        ]);
        file_put_contents(
            $this->dir . '/config/providers.php',
            "<?php\nreturn [\\" . CustomTransportProvider::class . "::class];\n",
        );

        $container = $this->boot();

        $sent = $container->get(Notifier::class)->send(new Email('a@b.test', 'S', 'T'));

        $this->assertSame('sdk', $sent->transport);
    }

    public function test_kernel_request_flushes_deferred_mail_after_handling(): void
    {
        $this->writeNotifyConfig([
            'transports' => ['memory' => ['driver' => 'memory']],
            'channels' => ['email' => ['transport' => 'memory']],
        ]);
        $app = Application::create($this->dir);
        $app->boot();
        $container = $app->container();
        $app->addMiddleware(new class($container) implements \Psr\Http\Server\MiddlewareInterface {
            public function __construct(private \Preflow\Core\Container\Container $c) {}

            public function process(\Psr\Http\Message\ServerRequestInterface $r, \Psr\Http\Server\RequestHandlerInterface $h): \Psr\Http\Message\ResponseInterface
            {
                $this->c->get(Notifier::class)->sendLater(new Email('a@b.test', 'S', 'T'));

                return new \Nyholm\Psr7\Response(200, [], 'ok');
            }
        });

        $response = $app->handle(new \Nyholm\Psr7\ServerRequest('GET', '/'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $container->get(TransportFactory::class)->get('memory')->sent());
    }

    public function test_handle_without_notify_config_still_works(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $app->addMiddleware(new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(\Psr\Http\Message\ServerRequestInterface $r, \Psr\Http\Server\RequestHandlerInterface $h): \Psr\Http\Message\ResponseInterface
            {
                return new \Nyholm\Psr7\Response(200, [], 'ok');
            }
        });

        $this->assertSame(200, $app->handle(new \Nyholm\Psr7\ServerRequest('GET', '/'))->getStatusCode());
    }

    public function test_run_postpones_the_flush_until_after_emitting(): void
    {
        $this->writeNotifyConfig([
            'transports' => ['memory' => ['driver' => 'memory']],
            'channels' => ['email' => ['transport' => 'memory']],
        ]);
        $app = Application::create($this->dir);
        $app->boot();
        $container = $app->container();
        $app->addMiddleware($this->queueingMiddleware($container, false));
        $sentAt = [];
        $mem = fn () => count($container->get(TransportFactory::class)->get('memory')->sent());
        $app->emitUsing(
            function () use (&$sentAt, $mem) { $sentAt[] = 'emit:' . $mem(); },
            function () use (&$sentAt, $mem) { $sentAt[] = 'finish:' . $mem(); },
        );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $app->run();

        $this->assertSame(['emit:0', 'finish:0'], $sentAt);
        $this->assertSame(1, $mem());
    }

    public function test_mail_queued_before_an_error_response_is_still_sent(): void
    {
        $this->writeNotifyConfig([
            'transports' => ['memory' => ['driver' => 'memory']],
            'channels' => ['email' => ['transport' => 'memory']],
        ]);
        $app = Application::create($this->dir);
        $app->boot();
        $container = $app->container();
        $app->addMiddleware($this->queueingMiddleware($container, true));

        try {
            $app->handle(new \Nyholm\Psr7\ServerRequest('GET', '/'));
        } catch (\Throwable) {
        }

        $this->assertCount(1, $container->get(TransportFactory::class)->get('memory')->sent());
    }

    public function test_logger_bound_after_boot_receives_deferred_failures(): void
    {
        $this->writeNotifyConfig([
            'transports' => ['bad' => ['driver' => 'nope']],
            'channels' => ['email' => ['transport' => 'bad']],
        ]);
        $app = Application::create($this->dir);
        $app->boot();
        $container = $app->container();
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };
        $container->instance(\Psr\Log\LoggerInterface::class, $logger);
        $app->addMiddleware($this->queueingMiddleware($container, false));

        $app->handle(new \Nyholm\Psr7\ServerRequest('GET', '/'));

        $this->assertCount(1, $logger->records);
    }

    private function queueingMiddleware(\Preflow\Core\Container\Container $c, bool $throw): \Psr\Http\Server\MiddlewareInterface
    {
        return new class($c, $throw) implements \Psr\Http\Server\MiddlewareInterface {
            public function __construct(private \Preflow\Core\Container\Container $c, private bool $throw) {}

            public function process(\Psr\Http\Message\ServerRequestInterface $r, \Psr\Http\Server\RequestHandlerInterface $h): \Psr\Http\Message\ResponseInterface
            {
                $this->c->get(Notifier::class)->sendLater(new Email('a@b.test', 'S', 'T'));
                if ($this->throw) {
                    throw new \RuntimeException('page broke');
                }

                return new \Nyholm\Psr7\Response(200, [], 'ok');
            }
        };
    }

    public function test_failing_emitter_does_not_release_the_client_but_still_flushes(): void
    {
        $this->writeNotifyConfig([
            'transports' => ['memory' => ['driver' => 'memory']],
            'channels' => ['email' => ['transport' => 'memory']],
        ]);
        $app = Application::create($this->dir);
        $app->boot();
        $container = $app->container();
        $app->addMiddleware($this->queueingMiddleware($container, false));
        $finished = false;
        $app->emitUsing(
            function () { throw new \RuntimeException('emit failed'); },
            function () use (&$finished) { $finished = true; },
        );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        try {
            $app->run();
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('emit failed', $e->getMessage());
        }

        $this->assertFalse($finished);
        $this->assertCount(1, $container->get(TransportFactory::class)->get('memory')->sent());
    }

    public function test_pipeline_error_then_failing_emit_does_not_release_the_client(): void
    {
        $this->writeNotifyConfig([
            'transports' => ['memory' => ['driver' => 'memory']],
            'channels' => ['email' => ['transport' => 'memory']],
        ]);
        $app = Application::create($this->dir);
        $app->boot();
        $container = $app->container();
        $app->addMiddleware($this->queueingMiddleware($container, false));
        $finished = false;
        $app->emitUsing(fn () => null, function () use (&$finished) { $finished = true; });
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        // A handle() failure: the router/kernel cannot be reached once the pipeline throws.
        $app->addMiddleware(new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(\Psr\Http\Message\ServerRequestInterface $r, \Psr\Http\Server\RequestHandlerInterface $h): \Psr\Http\Message\ResponseInterface
            {
                throw new \LogicException('no');
            }
        });
        $app->emitUsing(function () { throw new \LogicException('emit after handle'); }, function () use (&$finished) { $finished = true; });

        $this->expectException(\Throwable::class);
        try {
            $app->run();
        } finally {
            $this->assertFalse($finished);
            $this->assertCount(1, $container->get(TransportFactory::class)->get('memory')->sent());
        }
    }

    /** @param array<string, mixed> $config */
    private function writeNotifyConfig(array $config): void
    {
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    private function boot(): \Preflow\Core\Container\Container
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app->container();
    }
}
