<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\DirectoryCache;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

/** Registers an app cache the way a user's provider would. */
final class CacheSourceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->get(CacheRegistry::class)->register(new DirectoryCache('site.x', 'Site X', sys_get_temp_dir() . '/site_x'));
    }
}
