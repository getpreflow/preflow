<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

interface LoggerInterface
{
    public function log(string $message): void;
}

class FileLogger implements LoggerInterface
{
    public function log(string $message): void {}
}

class TestProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->bind(LoggerInterface::class, FileLogger::class);
        $container->singleton(FileLogger::class);
    }

    public function boot(Container $container): void
    {
    }
}

class ProviderWithBoot extends ServiceProvider
{
    public bool $booted = false;

    public function register(Container $container): void {}

    public function boot(Container $container): void
    {
        $this->booted = true;
    }
}

class CountingProvider extends ServiceProvider
{
    public static int $registered = 0;
    public static int $booted = 0;

    public function register(Container $container): void
    {
        self::$registered++;
    }

    public function boot(Container $container): void
    {
        self::$booted++;
    }
}

final class ServiceProviderTest extends TestCase
{
    public function test_provider_registers_bindings(): void
    {
        $container = new Container();
        $provider = new TestProvider();

        $provider->register($container);

        $result = $container->get(LoggerInterface::class);
        $this->assertInstanceOf(FileLogger::class, $result);
    }

    public function test_provider_singleton_works(): void
    {
        $container = new Container();
        $provider = new TestProvider();

        $provider->register($container);

        $a = $container->get(FileLogger::class);
        $b = $container->get(FileLogger::class);
        $this->assertSame($a, $b);
    }

    public function test_boot_is_called(): void
    {
        $container = new Container();
        $provider = new ProviderWithBoot();

        $provider->register($container);
        $provider->boot($container);

        $this->assertTrue($provider->booted);
    }

    public function test_container_registers_and_boots_providers(): void
    {
        $container = new Container();
        $provider = new ProviderWithBoot();

        $container->registerProvider($provider);
        $container->bootProviders();

        $this->assertTrue($provider->booted);
    }

    public function test_a_provider_class_registers_and_boots_once(): void
    {
        CountingProvider::$registered = CountingProvider::$booted = 0;
        $container = new Container();

        $this->assertFalse($container->hasProvider(CountingProvider::class));
        $container->registerProvider(new CountingProvider());
        $container->registerProvider(new CountingProvider());
        $container->bootProviders();

        $this->assertTrue($container->hasProvider(CountingProvider::class));
        $this->assertSame(1, CountingProvider::$registered);
        $this->assertSame(1, CountingProvider::$booted);
    }
}
