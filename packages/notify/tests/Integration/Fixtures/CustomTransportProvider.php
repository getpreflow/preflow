<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Integration\Fixtures;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Notify\Transport\InMemoryTransport;
use Preflow\Notify\TransportFactory;

/** Registers a 'custom' driver the way an app provider would wrap a provider SDK. */
final class CustomTransportProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        $container->get(TransportFactory::class)
            ->extend('custom', fn (array $config, string $name) => new InMemoryTransport($name));
    }
}
