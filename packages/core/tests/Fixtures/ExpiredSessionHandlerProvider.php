<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Http\Csrf\ExpiredSessionHandler;

/** Binds the handler in register(), after the Application has built its CsrfMiddleware. */
final class ExpiredSessionHandlerProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(ExpiredSessionHandler::class, RedirectingExpiredSessionHandler::class);
    }
}
