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
 * "model:staff" under the extension's "enabled_parts" to get them. The models' labels are
 * keys of the "rbac" group (lang/{locale}/rbac.php: types, fields, options).
 */
#[FolioExtension(name: 'rbac', label: 'Roles', icon: 'ui-users', version: '1.1.0', translations: 'rbac')]
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
     * Seeds the roles on every deploy, as `php preflow rbac:install` does (RoleStore::seed()):
     * the staff role "admin" (everything) when there is no staff role, then every role of
     * config/roles.php. An editor's change in Folio stays.
     */
    public function seed(Container $c): void
    {
        $c->get(RoleStore::class)->seed(RoleStore::config($c->get(Application::class)->basePath('config/roles.php')));
    }
}
