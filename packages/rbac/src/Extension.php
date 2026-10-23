<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\Folio\Extension\Extension as FolioExtensionBase;

/**
 * Roles managed in Folio (models/rbac_role.json); RbacServiceProvider answers can() from them.
 * Staff accounts (models/staff.json, Types/StaffRules) are an opt-in part: a site lists
 * "model:staff" under the extension's "enabled_parts" to get them.
 */
#[FolioExtension(name: 'rbac', label: 'Roles', icon: 'ui-users', version: '1.1.0')]
final class Extension extends FolioExtensionBase
{
    /** The staff type on the storage alias "staff" when the site has it, else on "default". */
    public function prepareModel(array $schema, Container $c): array
    {
        if (($schema['key'] ?? null) !== StaffManagers::TYPE) {
            return $schema;
        }
        // The DataManager is bound only with config/data.php (Container::has() is true for any class).
        $drivers = is_file($c->get(Application::class)->basePath('config/data.php')) ? $c->get(DataManager::class)->driverNames() : [];
        $schema['storage'] = in_array(StaffManagers::TYPE, $drivers, true) ? StaffManagers::TYPE : 'default';

        return $schema;
    }

    /**
     * Seeds the roles, never changing existing ones: the staff role "admin" (everything) when
     * there is no staff role, then every role of config/roles.php (preflow/roles' format,
     * population => name => patterns) that does not exist yet.
     */
    public function install(Container $c): void
    {
        $store = new RoleStore($c->get(DataManager::class));
        if ($store->rolesFor('staff') === []) {
            $store->ensure('staff', 'admin', 'Administrator', ['*']);
        }

        $file = $c->get(Application::class)->basePath('config/roles.php');
        $config = is_file($file) ? require $file : [];
        foreach (is_array($config) ? $config : [] as $population => $roles) {
            foreach (is_array($roles) ? $roles : [] as $name => $patterns) {
                $store->ensure((string) $population, (string) $name, ucfirst(str_replace('_', ' ', (string) $name)), RoleList::normalize($patterns));
            }
        }
    }
}
