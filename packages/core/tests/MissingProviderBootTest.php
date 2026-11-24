<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/** A class listed in config/providers.php that does not exist: loud in debug, logged in production. */
final class MissingProviderBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/missing_provider_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        RecordingLoggerProvider::$lines = [];
    }

    protected function tearDown(): void
    {
        foreach (['providers.php', 'app.php'] as $file) {
            @unlink($this->dir . '/config/' . $file);
        }
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    private function app(int $debug): Application
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>{$debug},'key'=>'missing-provider-test-key-0123456789'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\" . RecordingLoggerProvider::class . "::class, 'App\\\\Providers\\\\Missing'];\n");

        return Application::create($this->dir);
    }

    public function test_missing_provider_class_stops_boot_in_debug(): void
    {
        $app = $this->app(1);

        try {
            $app->boot();
            $this->fail('boot() should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('App\\Providers\\Missing', $e->getMessage());
            $this->assertStringContainsString('config/providers.php', $e->getMessage());
        }
    }

    public function test_missing_provider_class_is_logged_in_production(): void
    {
        $app = $this->app(0);
        $app->boot();

        $this->assertCount(1, RecordingLoggerProvider::$lines);
        $this->assertStringContainsString('error:', RecordingLoggerProvider::$lines[0]);
        $this->assertStringContainsString('App\\Providers\\Missing', RecordingLoggerProvider::$lines[0]);
    }
}

final class RecordingLoggerProvider extends ServiceProvider
{
    /** @var list<string> */
    public static array $lines = [];

    public function register(Container $container): void
    {
        $container->instance(LoggerInterface::class, new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                RecordingLoggerProvider::$lines[] = $level . ': ' . $message;
            }
        });
    }

    public function boot(Container $container): void {}
}
