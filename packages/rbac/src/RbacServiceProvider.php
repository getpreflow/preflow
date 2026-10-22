<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Rbac\Fields\PermissionsFieldType;
use Preflow\Rbac\Fields\RolesFieldType;

/**
 * Answers can() from the rbac_role records when the Folio extension "rbac" is enabled
 * (config/folio/extensions.json), and registers the field types rbac_permissions and
 * rbac_roles. In boot(), so it wins over preflow/roles, which sets its resolver in register().
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
        $container->get(Access::class)->setResolver(new RbacResolver(
            new RoleStore($container->get(DataManager::class)),
            $container->get(ExtensionState::class),
            $debug,
        ));

        $fieldTypes = $container->get(FieldTypeRegistry::class);
        $fieldTypes->register(new PermissionsFieldType($container->get(PermissionRegistry::class)));
        $fieldTypes->register(new RolesFieldType($container->get(DataManager::class), $container->get(TypeCatalog::class)));
    }
}
