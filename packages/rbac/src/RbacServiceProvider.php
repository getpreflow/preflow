<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Http\AdminAssets;
use Preflow\Rbac\Fields\PermissionsFieldType;
use Preflow\Rbac\Fields\RolesFieldType;

/**
 * Answers can() from the rbac_role records (RbacResolver; until `php preflow rbac:install`
 * staff may do everything) whenever this provider runs and the site has a data config. In
 * boot(), so it wins over preflow/roles, which sets its resolver in register(). Defines the
 * role type (RoleType) unless Folio's rbac admin did. The role store, the resolver, the
 * delegation check (PermissionCoverage) and the rbac strings (Messages) are container
 * instances. With Folio it also registers the field types rbac_permissions (its editor's
 * assets/rbac.css and rbac.js served by Folio) and rbac_roles.
 */
final class RbacServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(RoleStore::class, static fn (Container $c): RoleStore => new RoleStore($c->get(DataManager::class)));
    }

    public function boot(Container $container): void
    {
        // The DataManager is bound only with config/data.php (Container::has() is true for any class).
        if (!$container->bound(DataManager::class)) {
            return;
        }
        RoleType::define($container);
        $debug = (bool) $container->get(Application::class)->config()->get('app.debug', false);
        $resolver = new RbacResolver($container->get(RoleStore::class), $debug);
        $access = $container->get(Access::class);
        $access->setResolver($resolver);
        $container->instance(RbacResolver::class, $resolver);
        $coverage = new PermissionCoverage($access, $resolver);
        $container->instance(PermissionCoverage::class, $coverage);
        $messages = Messages::boot($container);
        $container->instance(Messages::class, $messages);

        if ($container->bound(FieldTypeRegistry::class)) {
            $this->folio($container, $coverage, $messages);
        }
    }

    /** Folio's part (when Folio is there): the field types and the permissions editor's assets. */
    private function folio(Container $container, PermissionCoverage $coverage, Messages $messages): void
    {
        $labels = $container->bound(DisplayLabels::class) ? $container->get(DisplayLabels::class) : null;
        $fieldTypes = $container->get(FieldTypeRegistry::class);
        $fieldTypes->register(new PermissionsFieldType($container->get(PermissionRegistry::class), $container->get(TypeCatalog::class), $coverage, $messages, $labels));
        $assets = $container->get(AdminAssets::class);
        $assets->add('rbac.css', dirname(__DIR__) . '/assets/rbac.css');
        $assets->add('rbac.js', dirname(__DIR__) . '/assets/rbac.js');
        $fieldTypes->register(new RolesFieldType($container->get(DataManager::class), $container->get(TypeCatalog::class), $coverage, $messages));
    }
}
