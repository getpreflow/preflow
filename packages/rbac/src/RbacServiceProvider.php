<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Access;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Folio\Extension\ExtensionRegistry;

/**
 * Answers can() from the rbac_role records when the Folio extension "rbac" is enabled
 * (config/folio/extensions.json). In boot(), so it wins over preflow/roles, which sets
 * its resolver in register().
 */
final class RbacServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        if (!$container->get(ExtensionRegistry::class)->isEnabled('rbac')) {
            return;
        }
        $debug = (bool) $container->get(Application::class)->config()->get('app.debug', false);
        $container->get(Access::class)->setResolver(new RbacResolver(new RoleStore($container->get(DataManager::class)), $debug));
    }
}
