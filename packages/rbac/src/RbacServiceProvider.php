<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Http\AdminAssets;
use Preflow\Rbac\Fields\PermissionsFieldType;
use Preflow\Rbac\Fields\RolesFieldType;

/**
 * Answers can() from the rbac_role records when the Folio extension "rbac" is enabled
 * (config/folio/extensions.json), and registers the field types rbac_permissions (its
 * editor's assets/rbac.css and rbac.js served by Folio) and rbac_roles. In boot(), so it
 * wins over preflow/roles, which sets its resolver in register(). An rbac_roles field for
 * the staff population is an error: staff role assignment has no delegation rule yet.
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
        self::refuseStaffRoleFields($container->get(TypeCatalog::class), $container->get(TypeRegistry::class));
        $debug = (bool) $container->get(Application::class)->config()->get('app.debug', false);
        $container->get(Access::class)->setResolver(new RbacResolver(
            new RoleStore($container->get(DataManager::class)),
            $container->get(ExtensionState::class),
            $debug,
        ));

        $fieldTypes = $container->get(FieldTypeRegistry::class);
        $fieldTypes->register(new PermissionsFieldType($container->get(PermissionRegistry::class), $container->get(TypeCatalog::class)));
        $assets = $container->get(AdminAssets::class);
        $assets->add('rbac.css', dirname(__DIR__) . '/assets/rbac.css');
        $assets->add('rbac.js', dirname(__DIR__) . '/assets/rbac.js');
        $fieldTypes->register(new RolesFieldType($container->get(DataManager::class), $container->get(TypeCatalog::class)));
    }

    /** An rbac_roles field whose population (field "population", else the type's "users") is staff. */
    private static function refuseStaffRoleFields(TypeCatalog $catalog, TypeRegistry $types): void
    {
        foreach ($catalog->all() as $listing) {
            foreach ($types->get($listing->key)->fields as $name => $field) {
                if ($field->type !== 'rbac_roles') {
                    continue;
                }
                $population = is_string($field->config['population'] ?? null) ? $field->config['population'] : $listing->users;
                if ($population === 'staff') {
                    throw new ExtensionException("Field {$listing->key}.{$name}: Assigning staff roles through Folio has no delegation rule yet; staff logins come from config/auth.php.");
                }
            }
        }
    }
}
