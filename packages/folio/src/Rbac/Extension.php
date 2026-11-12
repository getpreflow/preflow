<?php

declare(strict_types=1);

namespace Preflow\Folio\Rbac;

use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\RoleType;
use Preflow\Rbac\Staff\StaffType;
use Preflow\Rbac\StaffManagers;

/**
 * The roles and staff admin (preflow/rbac in Folio), Folio's built-in extension "rbac", there
 * when preflow/rbac is installed: the role type (the rbac core's, RoleType, with what
 * models/rbac_role.json adds for the admin: the menu section, the permissions editor), the
 * new-permissions notice (Types/RoleRules, NewPermissions, NewPermissionsScreen) and, as an
 * opt-in part, staff accounts (the core's StaffType with what models/staff.json adds: the list,
 * roles as rbac_roles; Types/StaffAdmin and StaffRules): a site lists "model:staff" under the
 * extension's "enabled_parts" to get them. Its labels and texts are the admin group "rbac-admin"
 * (Folio's lang/{locale}/rbac-admin.php); rbac's own refusals stay in its group "rbac".
 */
#[FolioExtension(name: 'rbac', label: 'Roles', icon: 'ui-users', version: '1.1.0', translations: 'rbac-admin')]
final class Extension extends \Preflow\Folio\Extension\Extension
{
    /**
     * The rbac core's role and staff types with the admin's additions (models/*.json): their keys
     * added, their fields' settings over the core's, the core's field order.
     */
    public function model(array $schema): array
    {
        $core = match ($schema['key'] ?? null) {
            RoleType::KEY => RoleType::schema(),
            StaffManagers::TYPE => StaffType::schema(),
            default => null,
        };
        if ($core === null) {
            return $schema;
        }
        $fields = $core['fields'];
        foreach ((array) ($schema['fields'] ?? []) as $name => $field) {
            $fields[$name] = array_replace((array) ($fields[$name] ?? []), (array) $field);
        }

        unset($core['fields'], $schema['fields']);

        return array_replace($core, $schema) + ['fields' => $fields];
    }

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
     * config/roles.php. An editor's change in Folio stays. A permission it grants that nothing
     * declares is printed in apply's output (and logged when a logger is bound).
     */
    public function seed(Container $c): void
    {
        $report = $c->get(RoleStore::class)->seed(RoleStore::config($c->get(Application::class)->basePath('config/roles.php')), $c->bound(PermissionRegistry::class) ? $c->get(PermissionRegistry::class) : null);
        foreach ($report as $line) {
            echo $line . "\n";
        }
    }
}
