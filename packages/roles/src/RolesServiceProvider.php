<?php

declare(strict_types=1);

namespace Preflow\Roles;

use Preflow\Auth\Access\Access;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

/**
 * Answers can() from config/roles.php. Without that file the default stays:
 * staff may do everything, members nothing.
 */
final class RolesServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $app = $container->get(Application::class);
        $debug = (bool) $app->config()->get('app.debug', false);
        $path = $app->basePath('config/roles.php');

        if (!file_exists($path)) {
            if ($debug) {
                error_log('preflow/roles: no config/roles.php, so staff may do everything and members nothing');
            }
            return;
        }

        $roles = require $path;
        $container->get(Access::class)->setResolver(new ConfigRoleResolver(is_array($roles) ? $roles : [], $debug));
    }
}
