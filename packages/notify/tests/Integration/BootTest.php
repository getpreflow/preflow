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
