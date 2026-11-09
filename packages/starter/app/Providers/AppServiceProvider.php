<?php

declare(strict_types=1);

namespace App\Providers;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        // Bind your own services here.
    }

    public function boot(Container $container): void
    {
        // Runs after every provider has registered.
    }
}
